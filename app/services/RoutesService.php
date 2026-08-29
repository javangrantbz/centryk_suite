<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/ReceivablesService.php';

/**
 * Field Sales & Routes (Centryk Business) — delivery runs, per-stop collections
 * and end-of-day driver settlement. Company-scoped; callers must have checked
 * membership and the 'routes' entitlement.
 *
 * A per-stop collection posts a receipt through ReceivablesService (when the
 * stop has a customer), so cash owed lands on the customer account. Trip cash
 * totals are recomputed from the stops after every change.
 */
class RoutesService
{
    private const ELECTRONIC = ['card', 'bank_transfer', 'xfer', 'cheque'];

    public static function summary(int $companyId): array
    {
        $pdo = DB::pdo();
        $r = $pdo->prepare("
            SELECT
                SUM(status = 'planned')  AS planned,
                SUM(status = 'out')      AS out_now,
                SUM(status = 'settling') AS settling,
                SUM(CASE WHEN status IN ('out','settling') THEN cash_expected ELSE 0 END) AS cash_in_transit,
                SUM(status = 'settling' AND settlement_submitted_at IS NOT NULL)          AS awaiting_approval,
                SUM(status = 'settled' AND ABS(COALESCE(cash_variance,0)) > 0.01
                    AND settled_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))                    AS variance_flags
            FROM route_trips WHERE company_id = :cid
        ");
        $r->execute(['cid' => $companyId]);
        $row = $r->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'planned'           => (int)($row['planned'] ?? 0),
            'out'               => (int)($row['out_now'] ?? 0),
            'settling'          => (int)($row['settling'] ?? 0),
            'awaiting_approval' => (int)($row['awaiting_approval'] ?? 0),
            'cash_in_transit'   => round((float)($row['cash_in_transit'] ?? 0), 2),
            'variance_flags'    => (int)($row['variance_flags'] ?? 0),
        ];
    }

    public static function routes(int $companyId): array
    {
        $stmt = DB::pdo()->prepare("
            SELECT r.id, r.name, r.notes, r.default_driver_name, r.status,
                   COUNT(t.id) AS trip_count,
                   SUM(t.status IN ('planned','out','settling')) AS open_trips
            FROM routes r
            LEFT JOIN route_trips t ON t.route_id = r.id
            WHERE r.company_id = :cid AND r.status = 'active'
            GROUP BY r.id, r.name, r.notes, r.default_driver_name, r.status
            ORDER BY r.name ASC
        ");
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function saveRoute(int $companyId, array $d, ?int $actorId): int
    {
        $pdo = DB::pdo();
        $id     = (int)($d['id'] ?? 0);
        $name   = trim((string)($d['name'] ?? ''));
        $notes  = trim((string)($d['notes'] ?? ''));
        $driver = trim((string)($d['default_driver_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Route name is required.');
        }

        if ($id > 0) {
            $chk = $pdo->prepare("SELECT id FROM routes WHERE id = :id AND company_id = :cid LIMIT 1");
            $chk->execute(['id' => $id, 'cid' => $companyId]);
            if (!$chk->fetch()) {
                throw new RuntimeException('Route not found.');
            }
            $pdo->prepare("UPDATE routes SET name = :n, notes = :no, default_driver_name = :dr WHERE id = :id")
                ->execute(['n' => $name, 'no' => $notes, 'dr' => $driver, 'id' => $id]);
        } else {
            $pdo->prepare("
                INSERT INTO routes (company_id, name, notes, default_driver_name, created_by)
                VALUES (:cid, :n, :no, :dr, :by)
            ")->execute(['cid' => $companyId, 'n' => $name, 'no' => $notes, 'dr' => $driver, 'by' => $actorId]);
            $id = (int)$pdo->lastInsertId();
        }

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'routes.route.saved', 'summary' => 'Saved route ' . $name,
            'metadata' => ['route_id' => $id],
        ]);
        return $id;
    }

    public static function trips(int $companyId, array $filters = []): array
    {
        $where = ['t.company_id = :cid'];
        $args  = ['cid' => $companyId];
        if (!empty($filters['route_id'])) {
            $where[] = 't.route_id = :rid';
            $args['rid'] = (int)$filters['route_id'];
        }
        $status = $filters['status'] ?? '';
        if (in_array($status, ['planned', 'out', 'settling', 'settled'], true)) {
            $where[] = 't.status = :st';
            $args['st'] = $status;
        } elseif ($status === 'open') {
            $where[] = "t.status IN ('planned','out','settling')";
        }

        $stmt = DB::pdo()->prepare("
            SELECT t.id, t.route_id, r.name AS route_name, t.trip_date, t.driver_name, t.status,
                   t.cash_expected, t.electronic_total, t.cash_declared, t.cash_variance,
                   t.settled_at,
                   (SELECT COUNT(*) FROM route_stops s WHERE s.trip_id = t.id) AS stop_count,
                   (SELECT COUNT(*) FROM route_stops s WHERE s.trip_id = t.id AND s.status IN ('paid','delivered','skipped')) AS done_count
            FROM route_trips t
            JOIN routes r ON r.id = t.route_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.trip_date DESC, t.id DESC
            LIMIT 200
        ");
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createTrip(int $companyId, int $routeId, string $date, string $driver, ?int $actorId): int
    {
        $pdo = DB::pdo();
        $route = $pdo->prepare("SELECT id, name, default_driver_name FROM routes WHERE id = :id AND company_id = :cid AND status = 'active' LIMIT 1");
        $route->execute(['id' => $routeId, 'cid' => $companyId]);
        $route = $route->fetch(PDO::FETCH_ASSOC);
        if (!$route) {
            throw new RuntimeException('Route not found.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            $date = date('Y-m-d');
        }
        $driver = trim($driver) !== '' ? trim($driver) : (string)$route['default_driver_name'];

        $dup = $pdo->prepare("SELECT id FROM route_trips WHERE route_id = :rid AND trip_date = :d LIMIT 1");
        $dup->execute(['rid' => $routeId, 'd' => $date]);
        if ($dup->fetch()) {
            throw new RuntimeException('That route already has a trip on ' . $date . '.');
        }

        $pdo->prepare("
            INSERT INTO route_trips (company_id, route_id, trip_date, driver_name, created_by)
            VALUES (:cid, :rid, :d, :dr, :by)
        ")->execute(['cid' => $companyId, 'rid' => $routeId, 'd' => $date, 'dr' => $driver, 'by' => $actorId]);
        $tripId = (int)$pdo->lastInsertId();

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'routes.trip.created',
            'summary' => 'Created trip on ' . $route['name'] . ' for ' . $date,
            'metadata' => ['trip_id' => $tripId, 'route_id' => $routeId, 'date' => $date],
        ]);
        return $tripId;
    }

    public static function trip(int $companyId, int $tripId): ?array
    {
        $pdo = DB::pdo();
        $t = $pdo->prepare("
            SELECT t.*, r.name AS route_name,
                   TRIM(CONCAT(COALESCE(su.first_name,''),' ',COALESCE(su.last_name,''))) AS submitted_by_name,
                   TRIM(CONCAT(COALESCE(ap.first_name,''),' ',COALESCE(ap.last_name,''))) AS approved_by_name
            FROM route_trips t
            JOIN routes r ON r.id = t.route_id
            LEFT JOIN users su ON su.id = t.settlement_submitted_by
            LEFT JOIN users ap ON ap.id = t.settlement_approved_by
            WHERE t.id = :id AND t.company_id = :cid LIMIT 1
        ");
        $t->execute(['id' => $tripId, 'cid' => $companyId]);
        $trip = $t->fetch(PDO::FETCH_ASSOC);
        if (!$trip) {
            return null;
        }

        $s = $pdo->prepare("
            SELECT s.id, s.customer_id, s.customer_name, s.seq, s.status,
                   s.amount_collected, s.method, s.ar_payment_id, s.note
            FROM route_stops s
            WHERE s.trip_id = :tid
            ORDER BY s.seq ASC, s.id ASC
        ");
        $s->execute(['tid' => $tripId]);
        $trip['stops'] = $s->fetchAll(PDO::FETCH_ASSOC);
        return $trip;
    }

    /** Reorder a trip's stops. $orderedStopIds must be every stop on the trip. */
    public static function reorderStops(int $companyId, int $tripId, array $orderedStopIds, ?int $actorId): void
    {
        $pdo = DB::pdo();
        $trip = self::lockableTrip($pdo, $companyId, $tripId);
        if ($trip['status'] === 'settled' || !empty($trip['settlement_submitted_at'])) {
            throw new RuntimeException('Settlement is submitted — stops are locked.');
        }

        $have = $pdo->prepare("SELECT id FROM route_stops WHERE trip_id = :t AND company_id = :c");
        $have->execute(['t' => $tripId, 'c' => $companyId]);
        $have = array_map('intval', $have->fetchAll(PDO::FETCH_COLUMN));
        $want = array_values(array_unique(array_map('intval', $orderedStopIds)));
        sort($have);
        $sortedWant = $want; sort($sortedWant);
        if ($have !== $sortedWant) {
            throw new InvalidArgumentException('The stop list must match the trip exactly.');
        }

        $upd = $pdo->prepare("UPDATE route_stops SET seq = :s WHERE id = :id AND trip_id = :t");
        foreach ($want as $i => $id) {
            $upd->execute(['s' => $i + 1, 'id' => $id, 't' => $tripId]);
        }

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'routes.stops.reordered',
            'summary' => 'Reordered stops on trip #' . $tripId,
            'metadata' => ['trip_id' => $tripId, 'order' => $want],
        ]);
    }

    public static function addStop(int $companyId, int $tripId, int $customerId, ?int $actorId): int
    {
        $pdo = DB::pdo();
        $trip = self::lockableTrip($pdo, $companyId, $tripId);
        if ($trip['status'] === 'settled') {
            throw new RuntimeException('This trip is settled and locked.');
        }

        $cust = $pdo->prepare("SELECT id, name FROM customers WHERE id = :id AND company_id = :cid LIMIT 1");
        $cust->execute(['id' => $customerId, 'cid' => $companyId]);
        $cust = $cust->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            throw new RuntimeException('Customer not found.');
        }

        $seq = (int)$pdo->query("SELECT COALESCE(MAX(seq),0)+1 FROM route_stops WHERE trip_id = " . (int)$tripId)->fetchColumn();
        $pdo->prepare("
            INSERT INTO route_stops (trip_id, company_id, customer_id, customer_name, seq)
            VALUES (:tid, :cid, :cust, :name, :seq)
        ")->execute(['tid' => $tripId, 'cid' => $companyId, 'cust' => $customerId, 'name' => $cust['name'], 'seq' => $seq]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Update a stop's outcome. When money is collected and the stop has a
     * customer, a receipt is posted to the ledger; changing/clearing it later
     * voids the previous receipt first.
     */
    public static function recordStop(int $companyId, int $stopId, array $d, ?int $actorId): void
    {
        $pdo = DB::pdo();

        $stop = $pdo->prepare("
            SELECT s.*, t.status AS trip_status, t.trip_date, r.name AS route_name
            FROM route_stops s
            JOIN route_trips t ON t.id = s.trip_id
            JOIN routes r ON r.id = t.route_id
            WHERE s.id = :id AND s.company_id = :cid LIMIT 1
        ");
        $stop->execute(['id' => $stopId, 'cid' => $companyId]);
        $stop = $stop->fetch(PDO::FETCH_ASSOC);
        if (!$stop) {
            throw new RuntimeException('Stop not found.');
        }
        if ($stop['trip_status'] === 'settled') {
            throw new RuntimeException('This trip is settled and locked.');
        }

        $status = in_array($d['status'] ?? '', ['pending', 'delivered', 'paid', 'skipped'], true) ? $d['status'] : $stop['status'];
        $amount = round((float)($d['amount_collected'] ?? 0), 2);
        $method = in_array($d['method'] ?? '', ['cash', 'card', 'bank_transfer', 'xfer', 'cheque', 'none'], true) ? $d['method'] : 'none';
        $note   = trim((string)($d['note'] ?? $stop['note']));
        if ($amount < 0) {
            $amount = 0;
        }
        if ($amount > 0 && $method === 'none') {
            throw new InvalidArgumentException('Choose how the payment was taken.');
        }
        if ($amount <= 0) {
            $method = 'none';
        }

        try {
            $pdo->beginTransaction();

            // Reverse an earlier receipt if the collection changed materially.
            $prevPaymentId = $stop['ar_payment_id'] ? (int)$stop['ar_payment_id'] : null;
            $keepPrevious = $prevPaymentId !== null
                && $amount > 0
                && abs($amount - (float)$stop['amount_collected']) < 0.005
                && $method === $stop['method'];

            $newPaymentId = $keepPrevious ? $prevPaymentId : null;

            if ($prevPaymentId !== null && !$keepPrevious) {
                ReceivablesService::voidPayment($companyId, $prevPaymentId, $actorId);
            }

            if (!$keepPrevious && $amount > 0 && $stop['customer_id']) {
                $receipt = ReceivablesService::recordPayment($companyId, (int)$stop['customer_id'], [
                    'amount'      => $amount,
                    'method'      => $method,
                    'received_on' => $stop['trip_date'],
                    'reference'   => 'Route ' . $stop['route_name'] . ' ' . $stop['trip_date'],
                    'notes'       => 'Collected on delivery',
                ], $actorId);
                $newPaymentId = $receipt['payment_id'];
            }

            $pdo->prepare("
                UPDATE route_stops
                SET status = :st, amount_collected = :amt, method = :m, ar_payment_id = :apid, note = :note
                WHERE id = :id
            ")->execute([
                'st' => $status, 'amt' => $amount, 'm' => $method,
                'apid' => $newPaymentId, 'note' => mb_substr($note, 0, 255), 'id' => $stopId,
            ]);

            self::recomputeTrip($pdo, (int)$stop['trip_id']);

            Audit::log([
                'actor_user_id' => $actorId, 'company_id' => $companyId,
                'event_type' => 'routes.stop.recorded',
                'summary' => 'Stop ' . $stop['customer_name'] . ' on ' . $stop['route_name']
                    . ': ' . $status . ($amount > 0 ? ' - ' . number_format($amount, 2) . ' ' . $method : ''),
                'metadata' => ['stop_id' => $stopId, 'trip_id' => (int)$stop['trip_id'], 'amount' => $amount, 'method' => $method],
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function setTripStatus(int $companyId, int $tripId, string $status, ?int $actorId): void
    {
        $pdo = DB::pdo();
        $trip = self::lockableTrip($pdo, $companyId, $tripId);

        $flow = ['planned' => ['out'], 'out' => ['settling', 'planned'], 'settling' => ['out']];
        if ($trip['status'] === 'settled') {
            throw new RuntimeException('This trip is settled and locked.');
        }
        if (!empty($trip['settlement_submitted_at'])) {
            throw new RuntimeException('Settlement is submitted — an admin must approve or reopen it.');
        }
        if (!in_array($status, $flow[$trip['status']] ?? [], true)) {
            throw new InvalidArgumentException('Cannot move a ' . $trip['status'] . ' trip to ' . $status . '.');
        }

        $pdo->prepare("UPDATE route_trips SET status = :st WHERE id = :id")
            ->execute(['st' => $status, 'id' => $tripId]);

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'routes.trip.status',
            'summary' => 'Trip #' . $tripId . ' -> ' . $status,
            'metadata' => ['trip_id' => $tripId, 'from' => $trip['status'], 'to' => $status],
        ]);
    }

    /**
     * Step 1 of settlement: whoever ran the route declares the cash handed in.
     * Variance is recorded and the stops lock, but the trip stays 'settling'
     * until an admin approves it. Re-submitting overwrites the declared figure.
     */
    public static function submitSettlement(int $companyId, int $tripId, float $cashDeclared, string $notes, ?int $actorId): array
    {
        $pdo = DB::pdo();
        $trip = self::lockableTrip($pdo, $companyId, $tripId);
        if (!in_array($trip['status'], ['out', 'settling'], true)) {
            throw new RuntimeException('Only a trip that is out or settling can be settled.');
        }
        if ($trip['status'] === 'settling' && !empty($trip['settlement_approved_at'])) {
            throw new RuntimeException('This trip is already settled.');
        }

        self::recomputeTrip($pdo, $tripId);
        $expected = (float)$pdo->query("SELECT cash_expected FROM route_trips WHERE id = " . (int)$tripId)->fetchColumn();
        $cashDeclared = round($cashDeclared, 2);
        $variance = round($cashDeclared - $expected, 2);

        $pdo->prepare("
            UPDATE route_trips
            SET status = 'settling', cash_declared = :cd, cash_variance = :cv, notes = :notes,
                settlement_submitted_at = NOW(), settlement_submitted_by = :by,
                settlement_approved_at = NULL, settlement_approved_by = NULL
            WHERE id = :id
        ")->execute([
            'cd' => $cashDeclared, 'cv' => $variance, 'notes' => mb_substr(trim($notes), 0, 255),
            'by' => $actorId, 'id' => $tripId,
        ]);

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'routes.trip.settlement_submitted',
            'summary' => 'Submitted settlement for trip #' . $tripId . ': expected ' . number_format($expected, 2)
                . ', declared ' . number_format($cashDeclared, 2) . ', variance ' . number_format($variance, 2)
                . (abs($variance) > 0.01 ? ' (FLAGGED)' : ''),
            'metadata' => ['trip_id' => $tripId, 'expected' => $expected, 'declared' => $cashDeclared, 'variance' => $variance],
        ]);

        return ['expected' => round($expected, 2), 'declared' => $cashDeclared, 'variance' => $variance];
    }

    /**
     * Step 2: a company admin signs off. The trip moves to 'settled' and locks.
     */
    public static function approveSettlement(int $companyId, int $tripId, int $actorId): void
    {
        $pdo = DB::pdo();
        self::assertCompanyAdmin($pdo, $companyId, $actorId);
        $trip = self::lockableTrip($pdo, $companyId, $tripId);
        if ($trip['status'] !== 'settling' || empty($trip['settlement_submitted_at'])) {
            throw new RuntimeException('This trip has no submitted settlement to approve.');
        }

        $pdo->prepare("
            UPDATE route_trips
            SET status = 'settled', settlement_approved_at = NOW(), settlement_approved_by = :by,
                settled_by = :by2, settled_at = NOW()
            WHERE id = :id
        ")->execute(['by' => $actorId, 'by2' => $actorId, 'id' => $tripId]);

        $selfApproved = (int)$trip['settlement_submitted_by'] === $actorId;
        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'routes.trip.settlement_approved',
            'summary' => 'Approved settlement for trip #' . $tripId
                . ($selfApproved ? ' (self-approved)' : '')
                . ' — declared ' . number_format((float)$trip['cash_declared'], 2)
                . ', variance ' . number_format((float)$trip['cash_variance'], 2),
            'metadata' => ['trip_id' => $tripId, 'self_approved' => $selfApproved],
        ]);
    }

    /** An admin reopens a submitted or settled trip back to editable 'settling'. */
    public static function reopenSettlement(int $companyId, int $tripId, int $actorId): void
    {
        $pdo = DB::pdo();
        self::assertCompanyAdmin($pdo, $companyId, $actorId);
        $trip = self::lockableTrip($pdo, $companyId, $tripId);
        if (!in_array($trip['status'], ['settling', 'settled'], true)) {
            throw new RuntimeException('Nothing to reopen on this trip.');
        }

        $pdo->prepare("
            UPDATE route_trips
            SET status = 'settling', settlement_submitted_at = NULL, settlement_submitted_by = NULL,
                settlement_approved_at = NULL, settlement_approved_by = NULL, settled_at = NULL
            WHERE id = :id
        ")->execute(['id' => $tripId]);

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'routes.trip.settlement_reopened',
            'summary' => 'Reopened settlement for trip #' . $tripId,
            'metadata' => ['trip_id' => $tripId, 'from' => $trip['status']],
        ]);
    }

    // ── internals ──────────────────────────────────────────────────────────

    private static function assertCompanyAdmin(PDO $pdo, int $companyId, int $userId): void
    {
        $m = $pdo->prepare("SELECT 1 FROM company_members WHERE company_id = :c AND user_id = :u AND role = 'admin' AND status = 'active' LIMIT 1");
        $m->execute(['c' => $companyId, 'u' => $userId]);
        if (!$m->fetch()) {
            throw new RuntimeException('Only a company admin can approve or reopen a settlement.');
        }
    }

    private static function lockableTrip(PDO $pdo, int $companyId, int $tripId): array
    {
        $t = $pdo->prepare("
            SELECT id, status, cash_declared, cash_variance,
                   settlement_submitted_at, settlement_submitted_by, settlement_approved_at
            FROM route_trips WHERE id = :id AND company_id = :cid LIMIT 1
        ");
        $t->execute(['id' => $tripId, 'cid' => $companyId]);
        $trip = $t->fetch(PDO::FETCH_ASSOC);
        if (!$trip) {
            throw new RuntimeException('Trip not found.');
        }
        return $trip;
    }

    private static function recomputeTrip(PDO $pdo, int $tripId): void
    {
        $in = "'" . implode("','", self::ELECTRONIC) . "'";
        $pdo->prepare("
            UPDATE route_trips SET
                cash_expected = (SELECT COALESCE(SUM(amount_collected),0) FROM route_stops WHERE trip_id = :t1 AND method = 'cash'),
                electronic_total = (SELECT COALESCE(SUM(amount_collected),0) FROM route_stops WHERE trip_id = :t2 AND method IN ($in))
            WHERE id = :t3
        ")->execute(['t1' => $tripId, 't2' => $tripId, 't3' => $tripId]);
    }
}
