<?php
/**
 * Company Insights: the charts and KPIs behind insights.php.
 *
 * Widgets are built per company and ONLY for apps the viewer is enrolled in
 * (user_app_access). Every widget is built in its own try/catch so one missing
 * table or an unreachable sibling app can never blank the whole page.
 *
 * Widget shape (consumed by insights.php):
 *   id, app, title, subtitle, type ('doughnut'|'bar'|'line'),
 *   labels[], datasets[ {label, data[]} ], empty (bool),
 *   sample {labels, datasets}  - ghost preview drawn behind the empty-state CTA,
 *   cta {title, body, label, href}, open {label, href}
 * KPI shape: label, value, sub, app, tone ('neutral'|'good'|'warn')
 *
 * Empty widgets are kept on purpose - an empty dashboard teaches the user what
 * the app could do for them, via the CTA.
 */
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/ConferenceService.php';
require_once __DIR__ . '/InsightsExternal.php';

class InsightsService
{
    /** Roles that may open Insights. */
    public const MANAGER_ROLES = ['owner', 'admin', 'manager'];

    /** @return array{role:string}|null  membership if the user may view insights for the company */
    public static function access(array $user, int $companyId): ?array
    {
        if ($companyId <= 0) {
            return null;
        }
        $stmt = DB::pdo()->prepare('
            SELECT cm.role, c.name, c.uuid
            FROM company_members cm JOIN companies c ON c.id = cm.company_id
            WHERE cm.user_id = :u AND cm.company_id = :c AND cm.status = "active" AND c.status = "active" LIMIT 1');
        $stmt->execute(['u' => (int)$user['id'], 'c' => $companyId]);
        $m = $stmt->fetch();
        if (!$m) {
            // Platform admins may look at any active company.
            if (!empty($user['is_admin'])) {
                $c = DB::pdo()->prepare('SELECT name, uuid FROM companies WHERE id = :c AND status = "active" LIMIT 1');
                $c->execute(['c' => $companyId]);
                $row = $c->fetch();
                return $row ? ['role' => 'admin', 'name' => $row['name'], 'uuid' => $row['uuid']] : null;
            }
            return null;
        }
        if (!in_array(strtolower((string)$m['role']), self::MANAGER_ROLES, true) && empty($user['is_admin'])) {
            return null;
        }
        return ['role' => (string)$m['role'], 'name' => (string)$m['name'], 'uuid' => (string)$m['uuid']];
    }

    /** Companies the user may open Insights for (for the picker). */
    public static function companiesFor(array $user): array
    {
        $sql = !empty($user['is_admin'])
            ? 'SELECT c.id, c.name FROM companies c WHERE c.status = "active" ORDER BY c.name LIMIT 200'
            : 'SELECT c.id, c.name FROM company_members cm JOIN companies c ON c.id = cm.company_id
               WHERE cm.user_id = :u AND cm.status = "active" AND c.status = "active"
                 AND cm.role IN ("owner","admin","manager") ORDER BY c.name';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute(!empty($user['is_admin']) ? [] : ['u' => (int)$user['id']]);
        return $stmt->fetchAll();
    }

    /** app keys the user is enrolled in. */
    public static function enrolledKeys(int $userId): array
    {
        $stmt = DB::pdo()->prepare('
            SELECT a.`key` FROM user_app_access ua JOIN apps a ON a.id = ua.app_id
            WHERE ua.user_id = :u AND a.status = "active"');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * @param array $company ['id','name','uuid']
     * @return array{kpis:array, widgets:array, apps:array}
     */
    public static function build(array $user, array $company, int $days): array
    {
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
        $enrolled = self::enrolledKeys((int)$user['id']);
        $has = static fn(string $k): bool => in_array($k, $enrolled, true);
        $cid = (int)$company['id'];
        $kpis = [];
        $widgets = [];

        $add = static function (callable $fn) use (&$kpis, &$widgets): void {
            try {
                $r = $fn();
                if (!$r) { return; }
                foreach ($r['kpis'] ?? [] as $k) { $kpis[] = $k; }
                foreach ($r['widgets'] ?? [] as $w) { $widgets[] = $w; }
            } catch (Throwable $e) {
                error_log('InsightsService widget failed: ' . $e->getMessage());
            }
        };

        $add(fn() => self::people($cid));
        if ($has('mypay'))       { $add(fn() => InsightsExternal::mypay($company, $days)); }
        if ($has('onepay'))      { $add(fn() => InsightsExternal::onepay($company, $days)); $add(fn() => self::store($cid)); }
        if ($has('invoice'))     { $add(fn() => self::invoices($cid, $days)); }
        if ($has('forms'))       { $add(fn() => self::forms($cid, $days)); }
        if ($has('calendar'))    { $add(fn() => self::calendar($cid, $days)); }
        if ($has('visionboard')) { $add(fn() => self::signage($cid, $days)); }
        if ($has('tv'))          { $add(fn() => self::tv($cid, $days)); }
        $add(fn() => self::cases($cid));
        $add(fn() => self::leads($cid));

        return ['kpis' => $kpis, 'widgets' => $widgets, 'days' => $days, 'enrolled' => array_values($enrolled)];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function q(string $sql, array $p = []): array
    {
        $s = DB::pdo()->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    private static function scalar(string $sql, array $p = [])
    {
        $s = DB::pdo()->prepare($sql);
        $s->execute($p);
        return $s->fetchColumn();
    }

    /** Fill missing days with 0 so a line/bar has a continuous axis. */
    private static function dailySeries(array $rows, string $keyCol, string $valCol, int $days): array
    {
        $by = [];
        foreach ($rows as $r) { $by[$r[$keyCol]] = (float)$r[$valCol]; }
        $labels = []; $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $labels[] = date('M j', strtotime($d));
            $data[] = $by[$d] ?? 0;
        }
        return [$labels, $data];
    }

    /** Collapse a long daily series to <= ~30 bars (weekly/monthly buckets) so charts stay readable. */
    public static function bucket(array $labels, array $data, int $maxPoints = 30): array
    {
        $n = count($data);
        if ($n <= $maxPoints) { return [$labels, $data]; }
        $size = (int)ceil($n / $maxPoints);
        $L = []; $D = [];
        for ($i = 0; $i < $n; $i += $size) {
            $L[] = $labels[$i];
            $D[] = array_sum(array_slice($data, $i, $size));
        }
        return [$L, $D];
    }

    public static function w(string $id, string $app, string $title, string $type, array $labels, array $datasets, array $sample, array $cta, array $open, string $subtitle = ''): array
    {
        $total = 0;
        foreach ($datasets as $ds) { $total += array_sum(array_map('floatval', $ds['data'])); }
        return [
            'id' => $id, 'app' => $app, 'title' => $title, 'subtitle' => $subtitle, 'type' => $type,
            'labels' => $labels, 'datasets' => $datasets, 'empty' => $total <= 0,
            'sample' => $sample, 'cta' => $cta, 'open' => $open,
        ];
    }

    public static function kpi(string $app, string $label, $value, string $sub = '', string $tone = 'neutral'): array
    {
        return ['app' => $app, 'label' => $label, 'value' => $value, 'sub' => $sub, 'tone' => $tone];
    }

    public static function money(float $n): string
    {
        return '$' . number_format($n, $n >= 1000 ? 0 : 2);
    }

    // ── core: people ─────────────────────────────────────────────────────────

    private static function people(int $cid): array
    {
        $rows = self::q('SELECT role, COUNT(*) n FROM company_members WHERE company_id = :c AND status = "active" GROUP BY role ORDER BY n DESC', ['c' => $cid]);
        $labels = array_map(fn($r) => ucfirst($r['role']), $rows);
        $data = array_map(fn($r) => (int)$r['n'], $rows);
        $members = array_sum($data);
        return [
            'kpis' => [self::kpi('core', 'Team members', $members, $members <= 1 ? 'Just you so far' : count($labels) . ' roles')],
            'widgets' => [self::w('people_roles', 'core', 'Your team by role', 'doughnut', $labels, [['label' => 'Members', 'data' => $data]],
                ['labels' => ['Admin', 'Manager', 'Employee'], 'datasets' => [['label' => 'Members', 'data' => [1, 3, 8]]]],
                ['title' => 'Bring your team on', 'body' => 'Invite employees so they can clock time, request leave and see their schedule.', 'label' => 'Invite members', 'href' => 'profile.php#companies'],
                ['label' => 'Manage team', 'href' => 'profile.php#companies'],
                $members > 1 ? $members . ' active members' : '')],
        ];
    }

    // ── calendar ─────────────────────────────────────────────────────────────

    private static function calendar(int $cid, int $days): array
    {
        $from = date('Y-m-d', strtotime("-$days day"));
        $to = date('Y-m-d', strtotime("+$days day"));
        $rows = self::q('SELECT event_type t, COUNT(*) n FROM events WHERE company_id = :c AND event_date BETWEEN :f AND :t GROUP BY event_type ORDER BY n DESC', ['c' => $cid, 'f' => $from, 't' => $to]);
        $labels = array_map(fn($r) => ucfirst($r['t']), $rows);
        $data = array_map(fn($r) => (int)$r['n'], $rows);
        $upcoming = (int)self::scalar('SELECT COUNT(*) FROM events WHERE company_id = :c AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)', ['c' => $cid]);
        $online = (int)self::scalar('SELECT COUNT(*) FROM event_conferences ec JOIN events e ON e.id = ec.event_id WHERE e.company_id = :c AND e.event_date >= CURDATE()', ['c' => $cid]);
        return [
            'kpis' => [self::kpi('calendar', 'Events, next 14 days', $upcoming, $online ? $online . ' online conference' . ($online > 1 ? 's' : '') . ' booked' : 'Plan your next one')],
            'widgets' => [self::w('calendar_types', 'calendar', 'Calendar activity', 'bar', $labels, [['label' => 'Events', 'data' => $data]],
                ['labels' => ['Meeting', 'Training', 'Deadline', 'Other'], 'datasets' => [['label' => 'Events', 'data' => [6, 3, 4, 2]]]],
                ['title' => 'Put the whole team on one calendar', 'body' => 'Add meetings, trainings and deadlines, invite employees, or host an online conference on Centryk TV.', 'label' => 'Add an event', 'href' => 'calendar.php?company_id=' . $cid],
                ['label' => 'Open calendar', 'href' => 'calendar.php?company_id=' . $cid],
                'Past and upcoming, ' . $days . ' days each way')],
        ];
    }

    // ── forms ────────────────────────────────────────────────────────────────

    private static function forms(int $cid, int $days): array
    {
        $from = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' day'));
        $rows = self::q('SELECT DATE(fr.submitted_at) d, COUNT(*) n FROM form_responses fr JOIN form_forms ff ON ff.id = fr.form_id WHERE ff.company_id = :c AND fr.submitted_at >= :f GROUP BY DATE(fr.submitted_at)', ['c' => $cid, 'f' => $from]);
        [$labels, $data] = self::dailySeries($rows, 'd', 'n', $days);
        [$labels, $data] = self::bucket($labels, $data);
        $total = (int)array_sum($data);
        $top = self::q('SELECT ff.title, COUNT(fr.id) n FROM form_forms ff LEFT JOIN form_responses fr ON fr.form_id = ff.id AND fr.submitted_at >= :f WHERE ff.company_id = :c GROUP BY ff.id, ff.title HAVING n > 0 ORDER BY n DESC LIMIT 5', ['c' => $cid, 'f' => $from]);
        $liveForms = (int)self::scalar('SELECT COUNT(*) FROM form_forms WHERE company_id = :c AND status = "open"', ['c' => $cid]);
        $cta = ['title' => 'Create a survey to find out what your customers want', 'body' => 'Polls, feedback and reviews in minutes. Share a link or QR code, then watch answers come in here.', 'label' => 'Create a survey', 'href' => 'forms.php?company_id=' . $cid];
        $open = ['label' => 'Open Forms', 'href' => 'forms.php?company_id=' . $cid];
        return [
            'kpis' => [self::kpi('forms', 'Form responses', $total, 'last ' . $days . ' days' . ($liveForms ? ', ' . $liveForms . ' open form' . ($liveForms > 1 ? 's' : '') : ''))],
            'widgets' => [
                self::w('forms_trend', 'forms', 'Responses over time', 'line', $labels, [['label' => 'Responses', 'data' => $data]],
                    ['labels' => ['W1', 'W2', 'W3', 'W4', 'W5', 'W6'], 'datasets' => [['label' => 'Responses', 'data' => [2, 5, 4, 9, 12, 16]]]], $cta, $open),
                self::w('forms_top', 'forms', 'Most answered forms', 'bar', array_map(fn($r) => mb_strimwidth((string)$r['title'], 0, 22, '…'), $top), [['label' => 'Responses', 'data' => array_map(fn($r) => (int)$r['n'], $top)]],
                    ['labels' => ['Customer survey', 'Event feedback', 'Staff poll'], 'datasets' => [['label' => 'Responses', 'data' => [18, 11, 6]]]], $cta, $open),
            ],
        ];
    }

    // ── invoices ─────────────────────────────────────────────────────────────

    private static function invoices(int $cid, int $days): array
    {
        $st = self::q('SELECT status, COUNT(*) n FROM invoices WHERE company_id = :c GROUP BY status ORDER BY n DESC', ['c' => $cid]);
        $labels = array_map(fn($r) => ucwords(str_replace('_', ' ', (string)$r['status'])), $st);
        $data = array_map(fn($r) => (int)$r['n'], $st);

        $months = $days >= 365 ? 12 : 6;
        $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' month'));
        $inv = self::q('SELECT DATE_FORMAT(issue_date, "%Y-%m") m, SUM(total) s FROM invoices WHERE company_id = :c AND issue_date >= :f AND status <> "draft" GROUP BY m', ['c' => $cid, 'f' => $from]);
        $pay = self::q('SELECT DATE_FORMAT(p.payment_date, "%Y-%m") m, SUM(p.amount) s FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.company_id = :c AND p.payment_date >= :f GROUP BY m', ['c' => $cid, 'f' => $from]);
        $iM = []; foreach ($inv as $r) { $iM[$r['m']] = (float)$r['s']; }
        $pM = []; foreach ($pay as $r) { $pM[$r['m']] = (float)$r['s']; }
        $mLabels = []; $iD = []; $pD = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
            $mLabels[] = date('M', strtotime($key . '-01'));
            $iD[] = round($iM[$key] ?? 0, 2);
            $pD[] = round($pM[$key] ?? 0, 2);
        }
        $out = (float)self::scalar('SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices WHERE company_id = :c AND status IN ("sent","overdue")', ['c' => $cid]);
        $overdue = (int)self::scalar('SELECT COUNT(*) FROM invoices WHERE company_id = :c AND status = "overdue"', ['c' => $cid]);
        $cta = ['title' => 'Send your first invoice', 'body' => 'Create an invoice, email or share a link, and see paid vs outstanding at a glance.', 'label' => 'Create an invoice', 'href' => 'switch.php?app=invoice'];
        $open = ['label' => 'Open Invoices', 'href' => 'switch.php?app=invoice'];
        return [
            'kpis' => [
                self::kpi('invoice', 'Outstanding', self::money($out), $overdue ? $overdue . ' overdue' : 'Nothing overdue', $overdue ? 'warn' : 'good'),
            ],
            'widgets' => [
                self::w('invoice_status', 'invoice', 'Invoices by status', 'doughnut', $labels, [['label' => 'Invoices', 'data' => $data]],
                    ['labels' => ['Paid', 'Sent', 'Overdue'], 'datasets' => [['label' => 'Invoices', 'data' => [12, 6, 2]]]], $cta, $open),
                self::w('invoice_cash', 'invoice', 'Invoiced vs collected', 'bar', $mLabels, [['label' => 'Invoiced', 'data' => $iD], ['label' => 'Collected', 'data' => $pD]],
                    ['labels' => ['Jan', 'Feb', 'Mar', 'Apr'], 'datasets' => [['label' => 'Invoiced', 'data' => [900, 1400, 1100, 1800]], ['label' => 'Collected', 'data' => [700, 1200, 1000, 1300]]]], $cta, $open),
            ],
        ];
    }

    // ── signage ──────────────────────────────────────────────────────────────

    private static function signage(int $cid, int $days): array
    {
        $screens = self::q('
            SELECT s.id, s.is_active, GREATEST(COALESCE(s.last_seen_at, "1970-01-01"), COALESCE(d.last_seen_at, "1970-01-01")) AS seen
            FROM vb_screens s LEFT JOIN vb_display_status d ON d.screen_id = s.id WHERE s.company_id = :c', ['c' => $cid]);
        $online = $offline = $never = 0;
        foreach ($screens as $s) {
            $t = strtotime((string)$s['seen']);
            if ($t <= 86400) { $never++; }
            elseif ($t >= time() - 300) { $online++; }
            else { $offline++; }
        }
        $span = min($days, 30);
        $from = date('Y-m-d 00:00:00', strtotime('-' . ($span - 1) . ' day'));
        $rows = self::q('SELECT DATE(created_at) d, COUNT(*) n FROM vb_activity_logs WHERE company_id = :c AND created_at >= :f GROUP BY DATE(created_at)', ['c' => $cid, 'f' => $from]);
        [$aL, $aD] = self::dailySeries($rows, 'd', 'n', $span);
        $playlists = (int)self::scalar('SELECT COUNT(*) FROM vb_playlists WHERE company_id = :c AND is_active = 1', ['c' => $cid]);
        $total = count($screens);
        $cta = ['title' => 'Put your promos on a screen', 'body' => 'Pair a TV in your store or lobby and rotate playlists, promotions and live data from your inventory.', 'label' => 'Set up a screen', 'href' => 'switch.php?app=visionboard'];
        $open = ['label' => 'Open Signage', 'href' => 'switch.php?app=visionboard'];
        return [
            'kpis' => [self::kpi('visionboard', 'Screens online', $online . ' / ' . $total, $playlists . ' active playlist' . ($playlists === 1 ? '' : 's'), $total && $online === $total ? 'good' : ($total && $online === 0 ? 'warn' : 'neutral'))],
            'widgets' => [
                self::w('signage_screens', 'visionboard', 'Screen health', 'doughnut', ['Online', 'Offline', 'Never paired'], [['label' => 'Screens', 'data' => [$online, $offline, $never]]],
                    ['labels' => ['Online', 'Offline'], 'datasets' => [['label' => 'Screens', 'data' => [4, 1]]]], $cta, $open),
                self::w('signage_activity', 'visionboard', 'Signage activity', 'bar', $aL, [['label' => 'Changes', 'data' => $aD]],
                    ['labels' => ['M', 'T', 'W', 'T', 'F', 'S', 'S'], 'datasets' => [['label' => 'Changes', 'data' => [3, 7, 4, 9, 6, 1, 2]]]], $cta, $open, 'Playlist, media and schedule changes'),
            ],
        ];
    }

    // ── store (OnePay "Sell on Store") ───────────────────────────────────────

    private static function store(int $cid): array
    {
        $rows = self::q('SELECT audience a, COUNT(*) n FROM store_listings WHERE company_id = :c AND enabled = 1 GROUP BY audience', ['c' => $cid]);
        $name = ['employee' => 'Employees only', 'market' => 'Centryk Market', 'both' => 'Everyone'];
        $labels = array_map(fn($r) => $name[$r['a']] ?? ucfirst((string)$r['a']), $rows);
        $data = array_map(fn($r) => (int)$r['n'], $rows);
        $live = array_sum($data);
        return [
            'kpis' => [self::kpi('onepay', 'Items on Store', $live, $live ? 'published' : 'Nothing published yet')],
            'widgets' => [self::w('store_listings', 'onepay', 'Store listings by audience', 'doughnut', $labels, [['label' => 'Listings', 'data' => $data]],
                ['labels' => ['Everyone', 'Employees only', 'Centryk Market'], 'datasets' => [['label' => 'Listings', 'data' => [8, 4, 3]]]],
                ['title' => 'Sell beyond your counter', 'body' => 'Publish items from OnePay to your storefront, to employees, or to the Centryk Market.', 'label' => 'Sell on Store', 'href' => 'sell.php'],
                ['label' => 'Manage listings', 'href' => 'sell.php'])],
        ];
    }

    // ── Centryk TV + conferences ─────────────────────────────────────────────

    private static function tv(int $cid, int $days): array
    {
        $from = date('Y-m-d 00:00:00', strtotime("-$days day"));
        $rows = self::q('SELECT e.status s, COUNT(*) n FROM tv_events e JOIN tv_organizations o ON o.id = e.organization_id WHERE o.company_id = :c AND e.start_at >= :f GROUP BY e.status', ['c' => $cid, 'f' => $from]);
        $labels = array_map(fn($r) => ucfirst((string)$r['s']), $rows);
        $data = array_map(fn($r) => (int)$r['n'], $rows);
        $live = (int)self::scalar('SELECT COUNT(*) FROM tv_events e JOIN tv_organizations o ON o.id = e.organization_id WHERE o.company_id = :c AND e.status = "live"', ['c' => $cid]);
        $base = ConferenceService::tvBaseUrl();
        return [
            'kpis' => [self::kpi('tv', 'Broadcasts live', $live, array_sum($data) . ' in the last ' . $days . ' days', $live ? 'good' : 'neutral')],
            'widgets' => [self::w('tv_events', 'tv', 'Broadcasts by status', 'doughnut', $labels, [['label' => 'Events', 'data' => $data]],
                ['labels' => ['Ended', 'Scheduled', 'Live'], 'datasets' => [['label' => 'Events', 'data' => [5, 3, 1]]]],
                ['title' => 'Go live or host a conference', 'body' => 'Stream events to customers, sell pay-per-view tickets, or run a free online meeting for your team.', 'label' => 'Open Centryk TV', 'href' => $base . '/'],
                ['label' => 'Open Centryk TV', 'href' => $base . '/dashboard'])],
        ];
    }

    // ── hub-native (shown only once there is data) ───────────────────────────

    private static function cases(int $cid): ?array
    {
        $rows = self::q('SELECT status s, COUNT(*) n FROM case_records WHERE company_id = :c GROUP BY status ORDER BY n DESC', ['c' => $cid]);
        if (!$rows) { return null; }
        $open = (int)self::scalar('SELECT COUNT(*) FROM case_records WHERE company_id = :c AND status NOT IN ("resolved","closed")', ['c' => $cid]);
        return [
            'kpis' => [self::kpi('core', 'Open cases', $open, '', $open > 10 ? 'warn' : 'neutral')],
            'widgets' => [self::w('cases_status', 'core', 'Cases by status', 'doughnut', array_map(fn($r) => ucwords(str_replace('_', ' ', (string)$r['s'])), $rows), [['label' => 'Cases', 'data' => array_map(fn($r) => (int)$r['n'], $rows)]],
                ['labels' => [], 'datasets' => []], ['title' => '', 'body' => '', 'label' => '', 'href' => 'cases.php'], ['label' => 'Open Cases', 'href' => 'cases.php'])],
        ];
    }

    private static function leads(int $cid): ?array
    {
        $rows = self::q('SELECT status s, COUNT(*) n, COALESCE(SUM(order_value),0) v FROM sales_leads WHERE company_id = :c GROUP BY status ORDER BY n DESC', ['c' => $cid]);
        if (!$rows) { return null; }
        $pipeline = 0.0;
        foreach ($rows as $r) { if (!in_array($r['s'], ['won', 'lost', 'converted'], true)) { $pipeline += (float)$r['v']; } }
        return [
            'kpis' => [self::kpi('core', 'Open pipeline', self::money($pipeline), 'sales leads')],
            'widgets' => [self::w('leads_status', 'core', 'Sales leads by status', 'doughnut', array_map(fn($r) => ucwords(str_replace('_', ' ', (string)$r['s'])), $rows), [['label' => 'Leads', 'data' => array_map(fn($r) => (int)$r['n'], $rows)]],
                ['labels' => [], 'datasets' => []], ['title' => '', 'body' => '', 'label' => '', 'href' => 'leads.php'], ['label' => 'Open Leads', 'href' => 'leads.php'])],
        ];
    }
}
