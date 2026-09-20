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
        'dropdown', 'rating', 'yes_no', 'number', 'date', 'section', 'email', 'phone',
    ];

    /**
     * Normalise a phone number, or null when it isn't valid.
     *   - Belize: 7 digits (600-2423), also with a 501 / +501 prefix -> "600-2423"
     *   - International: must start with "+", 8 to 15 digits -> "+<digits>"
     * Spaces, dashes, dots and brackets are ignored.
     */
    public static function normalizePhone(string $raw): ?string
    {
        $s = trim($raw);
        $international = str_starts_with($s, '+');
        $digits = preg_replace('/\D+/', '', $s) ?? '';

        if ($international && str_starts_with($digits, '501')) {
            $international = false;
            $digits = substr($digits, 3);
        } elseif (!$international && strlen($digits) === 10 && str_starts_with($digits, '501')) {
            $digits = substr($digits, 3);
        }

        if (!$international && preg_match('/^[2-9]\d{6}$/', $digits)) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3);
        }
        if ($international && preg_match('/^\d{8,15}$/', $digits) && $digits[0] !== '0') {
            return '+' . $digits;
        }
        return null;
    }

    /** Types whose answers are a pick from a fixed option list. */
    public const CHOICE_TYPES = ['single_choice', 'multiple_choice', 'dropdown'];

    /**
     * Fill-page theme presets a company can pick per form (seasonal
     * celebrations, brand moods). Colours drive the public page; post_tag is
     * appended to Facebook posts. 'default' is the original indigo look.
     */
    public const THEMES = [
        'default' => [
            'label' => 'Classic', 'accent' => '#4f46e5', 'accent_dark' => '#4338ca', 'tint' => '#eef2ff',
            'bg' => '#f1f5f9', 'banner' => '', 'emoji' => '', 'post_tag' => '',
        ],
        'carnival' => [
            'label' => 'Carnival', 'accent' => '#c026d3', 'accent_dark' => '#a21caf', 'tint' => '#fdf4ff',
            'bg' => 'linear-gradient(135deg,#fef3c7 0%,#fce7f3 45%,#ede9fe 100%)',
            'banner' => 'linear-gradient(90deg,#f59e0b,#ec4899,#8b5cf6,#10b981)',
            'emoji' => "\u{1F3AD}\u{1F941}\u{1F389}", 'post_tag' => "\u{1F389} Carnival vibes!",
        ],
        'independence' => [
            'label' => 'Independence', 'accent' => '#1d4ed8', 'accent_dark' => '#1e40af', 'tint' => '#eff6ff',
            'bg' => 'linear-gradient(135deg,#dbeafe 0%,#fee2e2 100%)',
            'banner' => 'linear-gradient(90deg,#1d4ed8,#ffffff,#dc2626)',
            'emoji' => "\u{1F1E7}\u{1F1FF}", 'post_tag' => "\u{1F1E7}\u{1F1FF} Celebrating Belize!",
        ],
        'festive' => [
            'label' => 'Festive', 'accent' => '#dc2626', 'accent_dark' => '#b91c1c', 'tint' => '#fef2f2',
            'bg' => 'linear-gradient(135deg,#fef2f2 0%,#ecfdf5 100%)',
            'banner' => 'linear-gradient(90deg,#dc2626,#16a34a)',
            'emoji' => "\u{1F384}\u{2728}", 'post_tag' => "\u{2728} Happy holidays!",
        ],
    ];

    /**
     * Words that flag a review for the moderator's attention. A nudge, not the
     * gate: a person still approves or rejects every review.
     */
    private const FLAG_WORDS = [
        'fuck', 'shit', 'bitch', 'bastard', 'asshole', 'dick', 'pussy', 'cunt', 'whore', 'slut',
        'nigger', 'nigga', 'retard', 'idiot', 'stupid', 'scam', 'thief', 'stole', 'rat', 'roach',
        'poison', 'sick', 'lawsuit', 'sue', 'kill', 'hate',
    ];

    private static function pdo(): PDO
    {
        return DB::pdo();
    }

    /** A theme preset by key (unknown keys fall back to 'default'). */
    public static function theme(string $key): array
    {
        return self::THEMES[$key] ?? self::THEMES['default'];
    }

    // ── Short links ───────────────────────────────────────────────────────

    /** URL-safe name: lowercase, apostrophes dropped, other runs of non-alphanumerics become one hyphen. */
    public static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $value);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $value) ?? '', '-');
        return $slug === '' ? 'review' : substr($slug, 0, 60);
    }

    /** True when $slug is not already used by another form in the company. */
    private static function slugFree(int $companyId, string $slug, int $exceptFormId): bool
    {
        $st = self::pdo()->prepare(
            "SELECT COUNT(*) FROM form_forms WHERE company_id = :cid AND slug = :s AND id <> :id"
        );
        $st->execute(['cid' => $companyId, 's' => $slug, 'id' => $exceptFormId]);
        return (int)$st->fetchColumn() === 0;
    }

    /**
     * The form's short-link name, generated from its title (suffixed -2, -3, ...
     * on a clash) and saved the first time it's needed.
     */
    public static function ensureSlug(int $formId, int $companyId): string
    {
        $form = self::getForm($formId, $companyId);
        if (!$form) {
            throw new RuntimeException('Form not found.');
        }
        $existing = trim((string)($form['slug'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $base = self::slugify((string)$form['title']);
        $slug = $base;
        for ($n = 2; !self::slugFree($companyId, $slug, $formId); $n++) {
            $slug = $base . '-' . $n;
        }
        self::pdo()->prepare("UPDATE form_forms SET slug = :s WHERE id = :id AND company_id = :cid AND slug IS NULL")
            ->execute(['s' => $slug, 'id' => $formId, 'cid' => $companyId]);
        return (string)(self::getForm($formId, $companyId)['slug'] ?? $slug);
    }

    /** Characters for generated short codes: no 0/o/1/l/i, which look alike on a printed card. */
    private const CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Lowercase letters, digits and hyphens; 3 to 30 characters. Throws when it can't be made valid. */
    private static function cleanCode(string $value): string
    {
        $code = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))) ?? '', '-');
        if (strlen($code) < 3 || strlen($code) > 30) {
            throw new RuntimeException('The short code must be 3 to 30 characters (letters, numbers and hyphens).');
        }
        return $code;
    }

    /** Short codes are unique across every company. */
    private static function codeFree(string $code, int $exceptFormId): bool
    {
        $st = self::pdo()->prepare("SELECT COUNT(*) FROM form_forms WHERE short_code = :c AND id <> :id");
        $st->execute(['c' => $code, 'id' => $exceptFormId]);
        return (int)$st->fetchColumn() === 0;
    }

    /** The form's short code (the /r/<code> link), generated and saved the first time it's needed. */
    public static function ensureShortCode(int $formId, int $companyId): string
    {
        $form = self::getForm($formId, $companyId);
        if (!$form) {
            throw new RuntimeException('Form not found.');
        }
        $existing = trim((string)($form['short_code'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $n = strlen(self::CODE_ALPHABET);
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $n - 1)];
            }
        } while (!self::codeFree($code, $formId));

        self::pdo()->prepare("UPDATE form_forms SET short_code = :c WHERE id = :id AND company_id = :cid AND short_code IS NULL")
            ->execute(['c' => $code, 'id' => $formId, 'cid' => $companyId]);
        return (string)(self::getForm($formId, $companyId)['short_code'] ?? $code);
    }

    /** Resolve /r/<short_code> to the form's share token, or null. */
    public static function tokenForCode(string $code): ?string
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z0-9-]{3,30}$/', $code)) {
            return null;
        }
        $st = self::pdo()->prepare("
            SELECT f.share_token FROM form_forms f JOIN companies c ON c.id = f.company_id
            WHERE f.short_code = :c AND c.status = 'active' LIMIT 1
        ");
        $st->execute(['c' => $code]);
        $tok = $st->fetchColumn();
        return $tok !== false ? (string)$tok : null;
    }

    /** Resolve /review/<company-slug>/<form-slug> to the form's share token, or null. */
    public static function tokenForShortLink(string $companySlug, string $formSlug): ?string
    {
        $companySlug = strtolower(trim($companySlug));
        $formSlug = strtolower(trim($formSlug));
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $companySlug) || !preg_match('/^[a-z0-9-]{1,80}$/', $formSlug)) {
            return null;
        }
        $st = self::pdo()->prepare("
            SELECT f.share_token
            FROM form_forms f JOIN companies c ON c.id = f.company_id
            WHERE c.store_slug = :cs AND f.slug = :fs AND c.status = 'active'
            LIMIT 1
        ");
        $st->execute(['cs' => $companySlug, 'fs' => $formSlug]);
        $tok = $st->fetchColumn();
        return $tok !== false ? (string)$tok : null;
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
            SELECT f.*, c.name AS company_name, c.logo AS company_logo
            FROM form_forms f
            JOIN companies c ON c.id = f.company_id
            WHERE f.share_token = :t
        ");
        $st->execute(['t' => $token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function createForm(int $companyId, int $userId, string $title, string $template = ''): int
    {
        if ($template === 'review' && trim($title) === '') {
            $title = 'Customer reviews';
        }
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
        $id = (int)self::pdo()->lastInsertId();
        if ($template === 'review') {
            self::applyReviewTemplate($id);
        }
        return $id;
    }

    /**
     * Ready-made customer review form: an overall rating, a "what did you
     * enjoy" pick and a comment. Turns review sharing on so the moderation
     * queue and Facebook posting are available straight away.
     */
    private static function applyReviewTemplate(int $formId): void
    {
        $pdo = self::pdo();
        $pdo->prepare("
            UPDATE form_forms
            SET reviews_enabled = 1,
                description = 'We would love to hear how we did. It takes less than a minute.',
                confirmation_message = 'Thank you for your feedback!'
            WHERE id = :id
        ")->execute(['id' => $formId]);

        $rows = [
            ['rating', 'How would you rate your experience?', '', 1, null, json_encode(['max' => 5])],
            ['single_choice', 'What did you enjoy most?', '', 0,
                json_encode(['Food / product', 'Service', 'Value for money', 'Atmosphere', 'Something else']), null],
            ['long_text', 'Tell us more (optional)', 'What stood out, good or bad?', 0, null, null],
        ];
        $ins = $pdo->prepare("
            INSERT INTO form_questions (form_id, sort_order, type, label, help_text, required, options, config)
            VALUES (:fid, :ord, :type, :label, :help, :req, :opts, :cfg)
        ");
        foreach ($rows as $i => [$type, $label, $help, $req, $opts, $cfg]) {
            $ins->execute([
                'fid' => $formId, 'ord' => $i + 1, 'type' => $type, 'label' => $label,
                'help' => $help, 'req' => $req, 'opts' => $opts, 'cfg' => $cfg,
            ]);
        }
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
        if (array_key_exists('short_code', $fields)) {
            $code = self::cleanCode((string)$fields['short_code']);
            if (!self::codeFree($code, $id)) {
                throw new RuntimeException('The short code "' . $code . '" is already taken by another form. Try a different one.');
            }
            $set[] = 'short_code = :sc';
            $params['sc'] = $code;
        }
        if (array_key_exists('slug', $fields)) {
            $slug = self::slugify((string)$fields['slug']);
            if (!self::slugFree($companyId, $slug, $id)) {
                throw new RuntimeException('Another form in your company already uses the short link name "' . $slug . '".');
            }
            $set[] = 'slug = :slug';
            $params['slug'] = $slug;
        }
        if (array_key_exists('theme', $fields)) {
            $set[] = 'theme = :theme';
            $params['theme'] = array_key_exists((string)$fields['theme'], self::THEMES) ? (string)$fields['theme'] : 'default';
        }
        if (array_key_exists('reviews_enabled', $fields)) {
            $set[] = 'reviews_enabled = :re';
            $params['re'] = !empty($fields['reviews_enabled']) ? 1 : 0;
        }
        if (array_key_exists('fb_auto_redirect', $fields)) {
            $set[] = 'fb_auto_redirect = :far';
            $params['far'] = !empty($fields['fb_auto_redirect']) ? 1 : 0;
        }
        if (array_key_exists('fb_recommend_url', $fields)) {
            $url = trim((string)$fields['fb_recommend_url']);
            if ($url !== '' && !preg_match('#^https://#i', $url)) {
                throw new RuntimeException('The Facebook link must start with https://');
            }
            $set[] = 'fb_recommend_url = :fbu';
            $params['fbu'] = mb_substr($url, 0, 500);
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
                     one_response_per_person, confirmation_message, theme, reviews_enabled,
                     fb_recommend_url, fb_auto_redirect, share_token)
                VALUES (:cid, :uid, :title, :descr, 'draft', :access, :orp, :cm, :theme, :re, :fbu, :far, :tok)
            ");
            $ins->execute([
                'cid'    => $companyId,
                'uid'    => $userId,
                'title'  => mb_substr($src['title'] . ' (copy)', 0, 200),
                'descr'  => $src['description'],
                'access' => $src['access'],
                'orp'    => (int)$src['one_response_per_person'],
                'cm'     => $src['confirmation_message'],
                'theme'  => $src['theme'],
                're'     => (int)$src['reviews_enabled'],
                'fbu'    => $src['fb_recommend_url'],
                'far'    => (int)$src['fb_auto_redirect'],
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
     * @param array{consent?:bool,name?:string} $share  the respondent's optional
     *        "you may share my review on Facebook" consent and first name; only
     *        acted on when the form has reviews_enabled
     * @throws RuntimeException on a validation problem the respondent can fix
     */
    public static function recordResponse(array $form, array $answers, ?int $userId, ?string $respondentKey, array $share = []): int
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
            if ($q['type'] === 'email') {
                $val = strtolower($val);
                if (strlen($val) > 254 || !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Please enter a valid email address for: ' . $q['label']);
                }
            }
            if ($q['type'] === 'phone') {
                $norm = self::normalizePhone($val);
                if ($norm === null) {
                    throw new RuntimeException('Please enter a valid phone number for: ' . $q['label'] . ' (for example 600-2423)');
                }
                $val = $norm;
            }
            if ($q['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                throw new RuntimeException('Please enter a valid date for: ' . $q['label']);
            }

            $clean[] = [$qid, mb_substr($val, 0, 5000), null];
        }

        if (!$clean) {
            throw new RuntimeException('Please answer at least one question.');
        }

        // Review sharing: only responses whose author consented enter the queue.
        $consent = !empty($form['reviews_enabled']) && !empty($share['consent']);
        $displayName = $consent ? self::cleanFirstName((string)($share['name'] ?? '')) : '';
        $status = 'none';
        $flagged = 0;
        $postText = null;
        if ($consent) {
            $postText = self::buildPostText($form, $questions, $clean, $displayName);
            if ($postText !== null) {
                $status = 'pending';
                $flagged = self::looksFlaggable($postText) ? 1 : 0;
            }
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO form_responses
                    (form_id, respondent_user_id, respondent_key, share_consent, display_name,
                     moderation_status, flagged, post_text)
                VALUES (:fid, :uid, :k, :sc, :dn, :ms, :fl, :pt)
            ")->execute([
                'fid' => $form['id'],
                'uid' => $userId,
                'k'   => $respondentKey,
                'sc'  => $consent ? 1 : 0,
                'dn'  => $displayName,
                'ms'  => $status,
                'fl'  => $flagged,
                'pt'  => $postText,
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

    // ── Reviews: consent, post text, moderation ──────────────────────────

    /** First name only (letters, hyphen, apostrophe), so a nickname can't smuggle in links or contact details. */
    private static function cleanFirstName(string $name): string
    {
        $name = trim(preg_replace('/[^\p{L}\p{M}\s\'-]/u', '', $name) ?? '');
        $name = preg_split('/\s+/u', $name)[0] ?? '';
        return mb_substr($name, 0, 30);
    }

    /** Case-insensitive whole-word match against FLAG_WORDS. */
    public static function looksFlaggable(string $text): bool
    {
        foreach (self::FLAG_WORDS as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '\b/iu', $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Draft the Facebook post from a response: star rating + the first
     * free-text comment + first name. Null when there's nothing worth posting
     * (no rating and no comment). The moderator can edit it before approving.
     *
     * @param array<int,array{0:int,1:?string,2:?string}> $clean [qid, text, json]
     */
    private static function buildPostText(array $form, array $questions, array $clean, string $name): ?string
    {
        $byQ = [];
        foreach ($clean as [$qid, $text]) {
            $byQ[$qid] = (string)$text;
        }
        $stars = null;
        $comment = '';
        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            if ($q['type'] === 'rating' && $stars === null && isset($byQ[$qid])) {
                $max = (int)($q['config']['max'] ?? 5);
                $n = (int)$byQ[$qid];
                $stars = $max === 5 ? str_repeat("\u{2B50}", $n) : $n . '/' . $max . " \u{2B50}";
            } elseif ($q['type'] === 'long_text' && $comment === '' && !empty($byQ[$qid])) {
                $comment = trim($byQ[$qid]);
            }
        }
        if ($stars === null && $comment === '') {
            return null;
        }

        $line = trim(($stars ?? '') . ($comment !== '' ? ' "' . mb_substr($comment, 0, 400) . '"' : ''));
        $line .= ' - ' . ($name !== '' ? $name : 'A guest');
        $tag = self::theme((string)($form['theme'] ?? 'default'))['post_tag'];
        return $tag !== '' ? $line . "\n\n" . $tag : $line;
    }

    /** Counts per moderation state for a form (tabs/badges). */
    public static function moderationCounts(int $formId): array
    {
        $st = self::pdo()->prepare("
            SELECT moderation_status, COUNT(*) FROM form_responses
            WHERE form_id = :fid AND moderation_status <> 'none' GROUP BY moderation_status
        ");
        $st->execute(['fid' => $formId]);
        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $n]) {
            $out[$k] = (int)$n;
        }
        return $out;
    }

    /** Reviews in one moderation state: flagged first, then newest first. */
    public static function moderationQueue(int $formId, string $status): array
    {
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }
        $st = self::pdo()->prepare("
            SELECT id, submitted_at, display_name, flagged, post_text,
                   fb_post_id, fb_posted_at, fb_error, moderated_at
            FROM form_responses
            WHERE form_id = :fid AND moderation_status = :s
            ORDER BY flagged DESC, submitted_at DESC, id DESC
            LIMIT 200
        ");
        $st->execute(['fid' => $formId, 's' => $status]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Moderate one review. $action: approve | reject | save_text (edit only).
     * $text, when given, replaces the drafted post text. Ownership is enforced
     * through the form's company.
     */
    public static function moderate(int $responseId, int $companyId, int $userId, string $action, ?string $text): array
    {
        $st = self::pdo()->prepare("
            SELECT r.id, r.form_id, r.fb_post_id
            FROM form_responses r
            JOIN form_forms f ON f.id = r.form_id
            WHERE r.id = :id AND f.company_id = :cid AND r.moderation_status <> 'none'
        ");
        $st->execute(['id' => $responseId, 'cid' => $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Review not found.');
        }
        if (!in_array($action, ['approve', 'reject', 'save_text'], true)) {
            throw new RuntimeException('Unknown action.');
        }
        if (!empty($row['fb_post_id'])) {
            throw new RuntimeException('This review is already on Facebook and can no longer be changed here.');
        }

        $set = ['moderated_by = :uid', 'moderated_at = NOW()'];
        $params = ['id' => $responseId, 'uid' => $userId];

        if ($text !== null) {
            $text = trim($text);
            if ($text === '') {
                throw new RuntimeException('The post text cannot be empty.');
            }
            $set[] = 'post_text = :pt';
            $params['pt'] = mb_substr($text, 0, 1500);
            $set[] = 'flagged = :fl';
            $params['fl'] = self::looksFlaggable($text) ? 1 : 0;
        }
        if ($action === 'approve') {
            $set[] = "moderation_status = 'approved'";
            $set[] = 'fb_error = NULL';
        } elseif ($action === 'reject') {
            $set[] = "moderation_status = 'rejected'";
        }

        self::pdo()->prepare('UPDATE form_responses SET ' . implode(', ', $set) . ' WHERE id = :id')
            ->execute($params);

        $out = self::pdo()->prepare("SELECT id, form_id, moderation_status, post_text FROM form_responses WHERE id = :id");
        $out->execute(['id' => $responseId]);
        return $out->fetch(PDO::FETCH_ASSOC);
    }

    /** Record the outcome of a Facebook post attempt on a response. */
    public static function recordFacebookResult(int $responseId, ?string $fbPostId, ?string $error): void
    {
        self::pdo()->prepare("
            UPDATE form_responses
            SET fb_post_id = :pid, fb_posted_at = " . ($fbPostId ? 'NOW()' : 'NULL') . ", fb_error = :err
            WHERE id = :id
        ")->execute([
            'pid' => $fbPostId,
            'err' => $error !== null ? mb_substr($error, 0, 250) : null,
            'id'  => $responseId,
        ]);
    }
}
