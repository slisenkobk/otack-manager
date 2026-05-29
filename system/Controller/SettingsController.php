<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\AuthGuard;
use App\Http\Request;
use App\Http\Response;
use App\Repository\SettingsRepository;

final class SettingsController extends BaseController
{
    /**
     * Whitelist of editable keys + their human labels and defaults.
     * Values shown on the form footer of public Forms read from these.
     */
    public const KEYS = [
        'timezone'              => ['label' => 'Timezone',           'default' => 'Europe/Kyiv'],
        'contact_company_name'  => ['label' => 'Company name',       'default' => ''],
        'contact_email'         => ['label' => 'Contact email',      'default' => ''],
        'contact_phone'         => ['label' => 'Contact phone',      'default' => ''],
        'contact_address'       => ['label' => 'Contact address',    'default' => ''],
        'contact_default_text'  => ['label' => 'Form footer text',   'default' => ''],
    ];

    private SettingsRepository $settings;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
        $this->settings = App::make('settings');
    }

    public function show(Request $req, array $params = []): void {
        $keys = array_keys(self::KEYS);
        $defaults = [];
        foreach (self::KEYS as $k => $cfg) { $defaults[$k] = $cfg['default']; }
        $values = $this->settings->getMany($keys, $defaults);

        $csrfToken = App::make('csrf')->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'settings', 'csrfToken' => $csrfToken,
        ]);
        $topbar  = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Settings',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Settings',
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('admin/settings', [
                'values'    => $values,
                'fields'    => self::KEYS,
                'csrfToken' => $csrfToken,
                'timezones' => \DateTimeZone::listIdentifiers(),
            ]),
        ]));
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
            $pairs[$k] = $val;
        }
        $this->settings->setMany($pairs);
        Response::redirect('/admin/settings?saved=1');
    }
}
