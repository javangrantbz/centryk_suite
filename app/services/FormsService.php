<?php

require_once __DIR__ . '/../core/DB.php';

/**
 * Centryk Forms — surveys, polls and feedback.
 *
 * A form has ordered questions; an "open" form collects responses through a
 * tokenised public link. All builder reads/writes are company-scoped; the
 * public side works purely off share_token.
 */
class FormsService
{
    /** Question types the builder offers. 'section' is a non-answerable divider. */
    public const TYPES = [
        'short_text', 'long_text', 'single_choice', 'multiple_choice',
        'dropdown', 'rating', 'yes_no', 'number', 'date', 'section',
    ];

    /** Types whose answers are a pick from a fixed option list. */
    public const CHOICE_TYPES = ['single_choice', 'multiple_choice', 'dropdown'];

    private static function pdo(): PDO
    {
        return DB::pdo();
    }

    // ── Company access ────────────────────────────────────────────────────

    /** Active companies where the user is admin or manager. */
    public static function companiesFor(int $userId): array
    {
        $st = self::pdo()->prepare("
            SELECT c.id, c.uuid, c.name, cm.role
            FROM company_members cm
            JOIN companies c ON c.id = cm.company_id
            WHERE cm.user_id = :uid AND cm.status = 'active'
              AND cm.role IN ('admin','manager') AND c.status = 'active'
            ORDER BY c.name ASC
        ");
        $st->execute(['uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Forms ─────────────────────────────────────────────────────────────

    public static function listForms(int $companyId): array
    {
        $st = self::pdo()->prepare("
            SELECT f.*,
                   (SELECT COUNT(*) FROM form_questions q WHERE q.form_id = f.id AND q.type <> 'section') AS question_count
            FROM form_forms f
            WHERE f.company_id = :cid
            ORDER BY f.updated_at DESC
        ");
        $st->execute(['cid' => $companyId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getForm(int $id, int $companyId): ?array
    {
        $st = self::pdo()->prepare("SELECT * FROM form_forms WHERE id = :id AND company_id = :cid");
        $st->execute(['id' => $id, 'cid' => $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Public lookup by share token. Returns the form regardless of status. */
    public static function getFormByToken(string $token): ?array
    {
        $st = self::pdo()->prepare("
            SELECT f.*, c.name AS company_name
            FROM form_forms f
            JOIN companies c ON c.id = f.company_id
            WHERE f.share_token = :t
        ");
        $st->execute(['t' => $token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function createForm(int $companyId, int $userId, string $title): int
    {
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 200) : 'Untitled form';
        $st = self::pdo()->prepare("
            INSERT INTO form_forms (company_id, created_by, title, share_token)
            VALUES (:cid, :uid, :title, :tok)
        ");
        $st->execute([
            'cid'   => $companyId,
            'uid'   => $userId,
            'title' => $title,
            'tok'   => bin2hex(random_bytes(16)),
        ]);
        return (int)self::pdo()->lastInsertId();
    }

    /**
     * Update form metadata. Only whitelisted fields; ignores the rest.
     * Opening a form is blocked unless it has at least one answerable question.
     */
    public static function updateForm(int $id, int $companyId, array $fields): void
    {
        $form = self::getForm($id, $companyId);
        if (!$form) {
            throw new RuntimeException('Form not found.');
        }

        $set = [];
        $params = ['id' => $id, 'cid' => $companyId];

        if (array_key_exists('title', $fields)) {
            $t = trim((string)$fields['title']);
            $set[] = 'title = :title';
            $params['title'] = $t !== '' ? mb_substr($t, 0, 200) : 'Untitled form';
        }
        if (array_key_exists('description', $fields)) {
            $set[] = 'description = :description';
            $params['description'] = mb_substr((string)$fields['description'], 0, 5000);
        }
        if (array_key_exists('confirmation_message', $fields)) {
            $set[] = 'confirmation_message = :cm';
            $params['cm'] = mb_substr((string)$fields['confirmation_message'], 0, 500);
        }
        if (array_key_exists('access', $fields)) {
            $access = in_array($fields['access'], ['public', 'login_required'], true) ? $fields['access'] : 'public';
            $set[] = 'access = :access';
            $params['access'] = $access;
        }
        if (array_key_exists('one_response_per_person', $fields)) {
            $set[] = 'one_response_per_person = :orp';
            $params['orp'] = !empty($fields['one_response_per_person']) ? 1 : 0;
        }
        if (array_key_exists('status', $fields)) {
            $status = in_array($fields['status'], ['draft', 'open', 'closed'], true) ? $fields['status'] : 'draft';
            if ($status === 'open') {
                $n = (int)self::pdo()->query(
                    "SELECT COUNT(*) FROM form_questions WHERE form_id = " . (int)$id . " AND type <> 'section'"
                )->fetchColumn();
                if ($n === 0) {
                    throw new RuntimeException('Add at least one question before opening the form.');
                }
            }
            $set[] = 'status = :status';
            $params['status'] = $status;
            $set[] = 'closed_at = ' . ($status === 'closed' ? 'NOW()' : 'NULL');
        }

        if (!$set) {
            return;
        }

        $sql = 'UPDATE form_forms SET ' . implode(', ', $set) . ' WHERE id = :id AND company_id = :cid';
        self::pdo()->prepare($sql)->execute($params);
    }

    public static function deleteForm(int $id, int $companyId): void
    {
        // form_questions / form_responses / form_answers cascade on FK.
        self::pdo()->prepare("DELETE FROM form_forms WHERE id = :id AND company_id = :cid")
            ->execute(['id' => $id, 'cid' => $companyId]);
    }

    /** Duplicate a form and its questions (not its responses). */
    public static function duplicateForm(int $id, int $companyId, int $userId): int
    {
        $src = self::getForm($id, $companyId);
        if (!$src) {
            throw new RuntimeException('Form not found.');
        }
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                INSERT INTO form_forms
                    (company_id, created_by, title, description, status, access,
                     one_response_per_person, confirmation_message, share_token)
                VALUES (:cid, :uid, :title, :descr, 'draft', :access, :orp, :cm, :tok)
            ");
            $ins->execute([
                'cid'    => $companyId,
                'uid'    => $userId,
                'title'  => mb_substr($src['title'] . ' (copy)', 0, 200),
                'descr'  => $src['description'],
                'access' => $src['access'],
                'orp'    => (int)$src['one_response_per_person'],
                'cm'     => $src['confirmation_message'],
                'tok'    => bin2hex(random_bytes(16)),
            ]);
            $newId = (int)$pdo->lastInsertId();

            $q = $pdo->prepare("
                INSERT INTO form_questions (form_id, sort_order, type, label, help_text, required, options, config)
                SELECT :newid, sort_order, type, label, help_text, required, options, config
                FROM form_questions WHERE form_id = :srcid
            ");
            $q->execute(['newid' => $newId, 'srcid' => $id]);

            $pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ── Questions ─────────────────────────────────────────────────────────

    public static function questions(int $formId): array
    {
        $st = self::pdo()->prepare("
            SELECT * FROM form_questions WHERE form_id = :fid ORDER BY sort_order ASC, id ASC
        ");
        $st->execute(['fid' => $formId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['options'] = $r['options'] !== null ? (json_decode($r['options'], true) ?: []) : [];
            $r['config']  = $r['config'] !== null ? (json_decode($r['config'], true) ?: []) : [];
        }
        return $rows;
    }

    /**
     * Insert or update one question. $data: id?, type, label, help_text,
     * required, options[], config{}.
     */
    public static function saveQuestion(int $formId, int $companyId, array $data): int
    {
        if (!self::getForm($formId, $companyId)) {
            throw new RuntimeException('Form not found.');
        }

        $type = in_array($data['type'] ?? '', self::TYPES, true) ? $data['type'] : 'short_text';
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            $label = $type === 'section' ? 'Section' : 'Untitled question';
        }
        $label = mb_substr($label, 0, 500);
        $help = mb_substr((string)($data['help_text'] ?? ''), 0, 500);
        $required = (!empty($data['required']) && $type !== 'section') ? 1 : 0;

        $options = null;
        if (in_array($type, self::CHOICE_TYPES, true)) {
            $opts = [];
            foreach ((array)($data['options'] ?? []) as $o) {
                $o = trim((string)$o);
                if ($o !== '') {
                    $opts[] = mb_substr($o, 0, 200);
                }
                if (count($opts) >= 50) {
                    break;
                }
            }
            if (!$opts) {
                $opts = ['Option 1', 'Option 2'];
            }
            $options = json_encode(array_values($opts), JSON_UNESCAPED_UNICODE);
        }

        $config = null;
        if ($type === 'rating') {
            $max = (int)($data['config']['max'] ?? 5);
            $config = json_encode(['max' => max(2, min(10, $max))]);
        } elseif ($type === 'number') {
            $cfg = [];
            if (isset($data['config']['min']) && $data['config']['min'] !== '') {
                $cfg['min'] = (float)$data['config']['min'];
            }
            if (isset($data['config']['max']) && $data['config']['max'] !== '') {
                $cfg['max'] = (float)$data['config']['max'];
            }
            $config = $cfg ? json_encode($cfg) : null;
        }

        $pdo = self::pdo();
        $qid = (int)($data['id'] ?? 0);

        if ($qid > 0) {
            $own = $pdo->prepare("SELECT id FROM form_questions WHERE id = :id AND form_id = :fid");
            $own->execute(['id' => $qid, 'fid' => $formId]);
            if (!$own->fetch()) {
                throw new RuntimeException('Question not found.');
            }
            $pdo->prepare("
                UPDATE form_questions
                SET type = :type, label = :label, help_text = :help, required = :req,
                    options = :options, config = :config
                WHERE id = :id AND form_id = :fid
            ")->execute([
                'type' => $type, 'label' => $label, 'help' => $help, 'req' => $required,
                'options' => $options, 'config' => $config, 'id' => $qid, 'fid' => $formId,
            ]);
            self::touch($formId);
            return $qid;
        }

        $nextOrder = (int)$pdo->query(
            "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM form_questions WHERE form_id = " . (int)$formId
        )->fetchColumn();

        $pdo->prepare("
            INSERT INTO form_questions (form_id, sort_order, type, label, help_text, required, options, config)
            VALUES (:fid, :ord, :type, :label, :help, :req, :options, :config)
        ")->execute([
            'fid' => $formId, 'ord' => $nextOrder, 'type' => $type, 'label' => $label,
            'help' => $help, 'req' => $required, 'options' => $options, 'config' => $config,
        ]);
        self::touch($formId);
        return (int)$pdo->lastInsertId();
    }

    public static function deleteQuestion(int $questionId, int $formId, int $companyId): void
    {
        if (!self::getForm($formId, $companyId)) {
            throw new RuntimeException('Form not found.');
        }
        self::pdo()->prepare("DELETE FROM form_questions WHERE id = :id AND form_id = :fid")
            ->execute(['id' => $questionId, 'fid' => $formId]);
        self::touch($formId);
    }

    /** @param int[] $orderedIds question ids in the desired order */
    public static function reorderQuestions(int $formId, int $companyId, array $orderedIds): void
    {
        if (!self::getForm($formId, $companyId)) {
            throw new RuntimeException('Form not found.');
        }
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE form_questions SET sort_order = :ord WHERE id = :id AND form_id = :fid");
            $ord = 1;
            foreach ($orderedIds as $qid) {
                $st->execute(['ord' => $ord++, 'id' => (int)$qid, 'fid' => $formId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::touch($formId);
    }

    private static function touch(int $formId): void
    {
        self::pdo()->prepare("UPDATE form_forms SET updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $formId]);
    }

    // ── Responses (public submit) ────────────────────────────────────────

    /**
     * Record a response to an open form.
     *
     * @param array<int,mixed> $answers  question_id => value
     *        (value is a string, or an array for multiple_choice)
     * @throws RuntimeException on a validation problem the respondent can fix
     */
    public static function recordResponse(array $form, array $answers, ?int $userId, ?string $respondentKey): int
    {
        if (($form['status'] ?? '') !== 'open') {
            throw new RuntimeException('This form is not accepting responses.');
        }

        if (!empty($form['one_response_per_person'])) {
            if ($userId !== null) {
                $dup = self::pdo()->prepare(
                    "SELECT id FROM form_responses WHERE form_id = :fid AND respondent_user_id = :uid LIMIT 1"
                );
                $dup->execute(['fid' => $form['id'], 'uid' => $userId]);
            } elseif ($respondentKey !== null) {
                $dup = self::pdo()->prepare(
                    "SELECT id FROM form_responses WHERE form_id = :fid AND respondent_key = :k LIMIT 1"
                );
                $dup->execute(['fid' => $form['id'], 'k' => $respondentKey]);
            } else {
                $dup = null;
            }
            if ($dup && $dup->fetch()) {
                throw new RuntimeException('You have already responded to this form.');
            }
        }

        $questions = self::questions((int)$form['id']);
        $clean = [];

        foreach ($questions as $q) {
            if ($q['type'] === 'section') {
                continue;
            }
            $qid = (int)$q['id'];
            $raw = $answers[$qid] ?? null;

            if ($q['type'] === 'multiple_choice') {
                $picked = [];
                foreach ((array)$raw as $v) {
                    $v = (string)$v;
                    if (in_array($v, $q['options'], true)) {
                        $picked[] = $v;
                    }
                }
                if (!$picked && $q['required']) {
                    throw new RuntimeException('Please answer: ' . $q['label']);
                }
                if ($picked) {
                    $clean[] = [$qid, null, json_encode(array_values($picked), JSON_UNESCAPED_UNICODE)];
                }
                continue;
            }

            $val = is_array($raw) ? '' : trim((string)$raw);
            if ($val === '') {
                if ($q['required']) {
                    throw new RuntimeException('Please answer: ' . $q['label']);
                }
                continue;
            }

            if (in_array($q['type'], ['single_choice', 'dropdown'], true) && !in_array($val, $q['options'], true)) {
                throw new RuntimeException('Invalid choice for: ' . $q['label']);
            }
            if ($q['type'] === 'yes_no' && !in_array($val, ['Yes', 'No'], true)) {
                throw new RuntimeException('Invalid answer for: ' . $q['label']);
            }
            if ($q['type'] === 'rating') {
                $max = (int)($q['config']['max'] ?? 5);
                $n = (int)$val;
                if ($n < 1 || $n > $max) {
                    throw new RuntimeException('Invalid rating for: ' . $q['label']);
                }
                $val = (string)$n;
            }
            if ($q['type'] === 'number' && !is_numeric($val)) {
                throw new RuntimeException('Please enter a number for: ' . $q['label']);
            }
            if ($q['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                throw new RuntimeException('Please enter a valid date for: ' . $q['label']);
            }

            $clean[] = [$qid, mb_substr($val, 0, 5000), null];
        }

        if (!$clean) {
            throw new RuntimeException('Please answer at least one question.');
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO form_responses (form_id, respondent_user_id, respondent_key)
                VALUES (:fid, :uid, :k)
            ")->execute([
                'fid' => $form['id'],
                'uid' => $userId,
                'k'   => $respondentKey,
            ]);
            $rid = (int)$pdo->lastInsertId();

            $ans = $pdo->prepare("
                INSERT INTO form_answers (response_id, question_id, answer_text, answer_json)
                VALUES (:rid, :qid, :text, :json)
            ");
            foreach ($clean as [$qid, $text, $json]) {
                $ans->execute(['rid' => $rid, 'qid' => $qid, 'text' => $text, 'json' => $json]);
            }

            $pdo->prepare("UPDATE form_forms SET response_count = response_count + 1 WHERE id = :fid")
                ->execute(['fid' => $form['id']]);

            $pdo->commit();
            return $rid;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ── Responses (builder read) ─────────────────────────────────────────

    public static function responses(int $formId, int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $st = self::pdo()->prepare("
            SELECT r.id, r.submitted_at, r.respondent_user_id,
                   NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), '') AS respondent_name
            FROM form_responses r
            LEFT JOIN users u ON u.id = r.respondent_user_id
            WHERE r.form_id = :fid
            ORDER BY r.submitted_at DESC, r.id DESC
            LIMIT $limit OFFSET $offset
        ");
        $st->execute(['fid' => $formId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $in = implode(',', array_map('intval', $ids));
        $aRows = self::pdo()->query("
            SELECT response_id, question_id, answer_text, answer_json
            FROM form_answers WHERE response_id IN ($in)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $byResponse = [];
        foreach ($aRows as $a) {
            $val = $a['answer_json'] !== null
                ? implode(', ', json_decode($a['answer_json'], true) ?: [])
                : (string)$a['answer_text'];
            $byResponse[(int)$a['response_id']][(int)$a['question_id']] = $val;
        }
        foreach ($rows as &$r) {
            $r['answers'] = $byResponse[(int)$r['id']] ?? [];
        }
        return $rows;
    }

    public static function responseCount(int $formId): int
    {
        $st = self::pdo()->prepare("SELECT COUNT(*) FROM form_responses WHERE form_id = :fid");
        $st->execute(['fid' => $formId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Per-question aggregates for the summary view. Choice/rating/yes_no get
     * a tallied option breakdown; free-text questions get a recent sample.
     */
    public static function summary(int $formId): array
    {
        $questions = self::questions($formId);
        $total = self::responseCount($formId);

        $answerRows = self::pdo()->prepare("
            SELECT a.question_id, a.answer_text, a.answer_json
            FROM form_answers a
            JOIN form_responses r ON r.id = a.response_id
            WHERE r.form_id = :fid
        ");
        $answerRows->execute(['fid' => $formId]);
        $all = $answerRows->fetchAll(PDO::FETCH_ASSOC);

        $byQ = [];
        foreach ($all as $a) {
            $byQ[(int)$a['question_id']][] = $a;
        }

        $out = [];
        foreach ($questions as $q) {
            if ($q['type'] === 'section') {
                continue;
            }
            $qid = (int)$q['id'];
            $rows = $byQ[$qid] ?? [];
            $answered = count($rows);

            $entry = [
                'id'       => $qid,
                'label'    => $q['label'],
                'type'     => $q['type'],
                'answered' => $answered,
                'total'    => $total,
            ];

            if (in_array($q['type'], ['single_choice', 'multiple_choice', 'dropdown'], true)) {
                $tally = array_fill_keys($q['options'], 0);
                foreach ($rows as $a) {
                    $vals = $a['answer_json'] !== null
                        ? (json_decode($a['answer_json'], true) ?: [])
                        : [(string)$a['answer_text']];
                    foreach ($vals as $v) {
                        if (array_key_exists($v, $tally)) {
                            $tally[$v]++;
                        }
                    }
                }
                $entry['breakdown'] = $tally;
            } elseif ($q['type'] === 'yes_no') {
                $tally = ['Yes' => 0, 'No' => 0];
                foreach ($rows as $a) {
                    if (isset($tally[$a['answer_text']])) {
                        $tally[$a['answer_text']]++;
                    }
                }
                $entry['breakdown'] = $tally;
            } elseif ($q['type'] === 'rating') {
                $max = (int)($q['config']['max'] ?? 5);
                $tally = array_fill_keys(range(1, $max), 0);
                $sum = 0;
                foreach ($rows as $a) {
                    $n = (int)$a['answer_text'];
                    if (isset($tally[$n])) {
                        $tally[$n]++;
                        $sum += $n;
                    }
                }
                $entry['breakdown'] = $tally;
                $entry['average'] = $answered ? round($sum / $answered, 2) : null;
            } elseif ($q['type'] === 'number') {
                $nums = array_map(static fn ($a) => (float)$a['answer_text'], $rows);
                $entry['average'] = $nums ? round(array_sum($nums) / count($nums), 2) : null;
                $entry['min'] = $nums ? min($nums) : null;
                $entry['max'] = $nums ? max($nums) : null;
            } else {
                $entry['samples'] = array_slice(array_map(
                    static fn ($a) => (string)$a['answer_text'],
                    $rows
                ), 0, 20);
            }

            $out[] = $entry;
        }

        return ['total' => $total, 'questions' => $out];
    }

    /** Full response set as CSV rows (first row = header). */
    public static function csv(int $formId): array
    {
        $questions = array_values(array_filter(
            self::questions($formId),
            static fn ($q) => $q['type'] !== 'section'
        ));

        $header = ['Response #', 'Submitted', 'Respondent'];
        foreach ($questions as $q) {
            $header[] = $q['label'];
        }
        $out = [$header];

        $st = self::pdo()->prepare("
            SELECT r.id, r.submitted_at,
                   NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), '') AS respondent_name
            FROM form_responses r
            LEFT JOIN users u ON u.id = r.respondent_user_id
            WHERE r.form_id = :fid
            ORDER BY r.submitted_at ASC, r.id ASC
        ");
        $st->execute(['fid' => $formId]);
        $responses = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$responses) {
            return $out;
        }

        $ids = implode(',', array_map(static fn ($r) => (int)$r['id'], $responses));
        $aRows = self::pdo()->query("
            SELECT response_id, question_id, answer_text, answer_json
            FROM form_answers WHERE response_id IN ($ids)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($aRows as $a) {
            $val = $a['answer_json'] !== null
                ? implode('; ', json_decode($a['answer_json'], true) ?: [])
                : (string)$a['answer_text'];
            $map[(int)$a['response_id']][(int)$a['question_id']] = $val;
        }

        $i = 1;
        foreach ($responses as $r) {
            $line = [
                $i++,
                $r['submitted_at'],
                $r['respondent_name'] ?: 'Anonymous',
            ];
            foreach ($questions as $q) {
                $line[] = $map[(int)$r['id']][(int)$q['id']] ?? '';
            }
            $out[] = $line;
        }
        return $out;
    }
}
