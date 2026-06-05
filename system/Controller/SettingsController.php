<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\AuthGuard;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AppBackupRepository;
use App\Repository\AppVersionRepository;
use App\Repository\SettingsRepository;
use App\Service\Updater;
use App\View\Renderer;

final class SettingsController extends BaseController
{
    /**
     * Whitelist of editable keys + their human labels and defaults.
     * Values shown on the form footer of public Forms read from these.
     */
    public const KEYS = [
        'app_name'              => ['label' => 'App name',           'default' => ''],
        'app_color'             => ['label' => 'Brand color',        'default' => ''],
        'timezone'              => ['label' => 'Timezone',           'default' => 'Europe/Kyiv'],
        'default_locale'        => ['label' => 'Default language',   'default' => 'en'],
        'contact_company_name'  => ['label' => 'Company name',       'default' => ''],
        'contact_email'         => ['label' => 'Contact email',      'default' => ''],
        'contact_phone'         => ['label' => 'Contact phone',      'default' => ''],
        'contact_address'       => ['label' => 'Contact address',    'default' => ''],
        'contact_default_text'  => ['label' => 'Form footer text',   'default' => ''],
    ];

    public function __construct(
        Renderer $view,
        ?array $user,
        private SettingsRepository $settings,
        private Updater $updater,
        private AppVersionRepository $appVersions,
        private AppBackupRepository $appBackups,
        private Csrf $csrf,
    ) {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
    }

    public function show(Request $req, array $params = []): void {
        $keys = array_keys(self::KEYS);
        $defaults = [];
        foreach (self::KEYS as $k => $cfg) { $defaults[$k] = $cfg['default']; }
        $values = $this->settings->getMany($keys, $defaults);

        $tab = (string)($req->query['tab'] ?? 'workspace');
        $allowedTabs = ['workspace', 'contact'];
        if (Updater::isEnabled()) $allowedTabs[] = 'updates';
        if (!in_array($tab, $allowedTabs, true)) $tab = 'workspace';

        // Updates tab needs the cached payload from the updater service.
        // We read the cache only (no network call here) — the dashboard
        // is responsible for refreshing the cache on its own cadence.
        $updates = $tab === 'updates'
            ? $this->updater->cachedPayload()
            : null;
        $currentVersionRow = $tab === 'updates'
            ? $this->appVersions->current()
            : null;
        $versionHistory = $tab === 'updates'
            ? $this->appVersions->listRecent(20)
            : [];
        $backups = $tab === 'updates'
            ? $this->appBackups->listAll()
            : [];

        $csrfToken = $this->csrf->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'settings', 'csrfToken' => $csrfToken,
        ]);
        $topbar  = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => t('settings.title'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => t('settings.title'),
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('admin/settings', [
                'values'         => $values,
                'fields'         => self::KEYS,
                'csrfToken'      => $csrfToken,
                'timezones'      => $this->timezonesWithOffsets(),
                'currentTab'     => $tab,
                'updatesEnabled' => Updater::isEnabled(),
                'updates'        => $updates,
                'currentVersion' => $currentVersionRow,
                'versionHistory' => $versionHistory,
                'backups'        => $backups,
            ]),
        ]));
    }

    /**
     * Build a flat list of timezones with their current UTC offset string,
     * sorted alphabetically. Used to render the searchable timezone picker.
     *
     * @return array<int, array{tz:string, offset:string}>
     */
    private function timezonesWithOffsets(): array {
        $now = new \DateTime('now');
        $rows = [];
        foreach (\DateTimeZone::listIdentifiers() as $tz) {
            $now->setTimezone(new \DateTimeZone($tz));
            $rows[] = ['tz' => $tz, 'offset' => $now->format('P')];
        }
        return $rows;
    }

    public function update(Request $req, array $params = []): void {
        $pairs = [];
        foreach (self::KEYS as $k => $cfg) {
            if (!array_key_exists($k, $req->post)) continue;
            $raw = (string)$req->post[$k];
            // contact_default_text is Quill HTML — sanitise; everything else is plaintext.
            $val = $k === 'contact_default_text'
                ? (trim($raw) === '' ? '' : \App\Service\HtmlSanitizer::clean($raw))
                : trim($raw);
            if ($k === 'timezone' && $val !== '') {
                if (!in_array($val, \DateTimeZone::listIdentifiers(), true)) continue;
            }
            if ($k === 'app_color' && $val !== '') {
                // Accept #RRGGBB only — input type=color always sends 7 chars.
                if (!preg_match('/^#[0-9a-f]{6}$/i', $val)) continue;
                $val = strtolower($val);
            }
            if ($k === 'default_locale') {
                if (!in_array($val, available_locales(), true)) continue;
            }
            $pairs[$k] = $val;
        }
        $this->settings->setMany($pairs);
        $tab = (string)($req->post['_tab'] ?? 'workspace');
        if (!in_array($tab, ['workspace', 'contact'], true)) $tab = 'workspace';
        Response::redirect('/admin/settings?tab=' . $tab . '&saved=1');
    }
}
