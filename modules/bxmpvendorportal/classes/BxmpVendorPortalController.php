<?php
/**
 * Base class of every vendor-portal front controller.
 *
 * Security model (server side, every request):
 *  1. PS customer must be logged in ($auth) — otherwise redirect to the login page (back to the portal);
 *  2. the customer must have an active membership (BxmpVendorUser::getMembershipForCustomer) of an active vendor —
 *     otherwise 403 "not a vendor";
 *  3. the role must hold the page permission ($permission) and every action re-checks its own permission
 *     ({@see requirePermission()}) — otherwise 403;
 *  4. every POST must carry the per-session CSRF token — otherwise 403;
 *  5. every read/write is scoped to $this->idVendor; foreign ids answer 404 (no existence leak).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\MarketplaceVendor\Domain\RolePolicy;

abstract class BxmpVendorPortalController extends ModuleFrontController
{
    public $auth = true;
    public $guestAllowed = false;
    public $ssl = true;

    /** controller name (key of Bxmpvendorportal::PAGES) */
    protected string $portalPage = 'dashboard';
    /** permission required to open the page at all */
    protected string $permission = RolePolicy::DASHBOARD_VIEW;

    protected ?array $membership = null;
    protected int $idVendor = 0;
    protected string $role = '';
    protected ?BxmpVendor $vendorModel = null;

    /** null = allowed; otherwise [http status, reason key, message] */
    protected ?array $denied = null;

    /** @var Bxmpvendorportal */
    public $module;

    public function init()
    {
        $this->authRedirection = urlencode($this->context->link->getModuleLink('bxmpvendorportal', $this->portalPage));
        parent::init(); // redirects anonymous visitors to the login page

        $this->membership = BxmpVendorUser::getMembershipForCustomer((int) $this->context->customer->id);
        if ($this->membership === null) {
            $this->deny(403, 'not_vendor', $this->trans('This area is reserved to marketplace vendors. Your account is not linked to an active vendor.', [], 'Modules.Bxmpvendorportal.Shop'));

            return;
        }
        $this->idVendor = (int) $this->membership['id_vendor'];
        $this->role = (string) $this->membership['role'];
        $this->vendorModel = new BxmpVendor($this->idVendor);
        if (!RolePolicy::can($this->role, $this->permission)) {
            $this->deny(403, 'forbidden', $this->trans('Your vendor role (%role%) does not give access to this page.', ['%role%' => $this->role], 'Modules.Bxmpvendorportal.Shop'));
        }
    }

    protected function deny(int $status, string $reason, string $message): void
    {
        if ($this->denied === null) {
            $this->denied = [$status, $reason, $message];
            if ($reason !== 'not_found') {
                Logger::channel('vendor')->warning(sprintf('Portal access denied (%s) on %s', $reason, $this->portalPage), [
                    'id_customer' => (int) $this->context->customer->id, 'id_vendor' => $this->idVendor, 'role' => $this->role,
                    'action' => (string) Tools::getValue('action'), 'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                ]);
            }
        }
    }

    /** Re-checks a permission for one action; denies (403) and returns false when missing. */
    protected function requirePermission(string $permission): bool
    {
        if (RolePolicy::can($this->role, $permission)) {
            return true;
        }
        $this->deny(403, 'forbidden', $this->trans('Your vendor role (%role%) is not allowed to perform this action.', ['%role%' => $this->role], 'Modules.Bxmpvendorportal.Shop'));

        return false;
    }

    protected function notFound(): void
    {
        $this->deny(404, 'not_found', $this->trans('Not found.', [], 'Modules.Bxmpvendorportal.Shop'));
    }

    // ------------------------------------------------------------------ CSRF

    public function csrfToken(): string
    {
        $token = (string) $this->context->cookie->bxmpv_csrf;
        if (strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->context->cookie->bxmpv_csrf = $token;
            $this->context->cookie->write();
        }

        return $token;
    }

    protected function csrfValid(): bool
    {
        $sent = (string) Tools::getValue('csrf_token', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string) $this->context->cookie->bxmpv_csrf;

        return strlen($expected) === 64 && hash_equals($expected, $sent);
    }

    // ------------------------------------------------------------------ lifecycle

    public function setMedia()
    {
        parent::setMedia();
        $this->registerStylesheet('bxmpv-portal', 'modules/bxmpvendorportal/views/css/portal.css', ['media' => 'all', 'priority' => 200]);
        $this->registerJavascript('bxmpv-portal', 'modules/bxmpvendorportal/views/js/portal.js', ['position' => 'bottom', 'priority' => 200]);
    }

    public function postProcess()
    {
        if ($this->denied !== null) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->csrfValid()) {
                $this->deny(403, 'csrf', $this->trans('Invalid or expired security token. Please reload the page and try again.', [], 'Modules.Bxmpvendorportal.Shop'));

                return;
            }
            $this->processPost((string) Tools::getValue('action'));
        } else {
            $this->processGet((string) Tools::getValue('action'));
        }
    }

    /** POST actions (CSRF already verified). Must call requirePermission() for each action. */
    protected function processPost(string $action): void
    {
        $this->deny(400, 'bad_request', 'Unsupported action.');
    }

    /** Optional GET actions (downloads...). */
    protected function processGet(string $action): void
    {
    }

    public function initContent()
    {
        parent::initContent();
        $this->assignCommon();
        if ($this->denied !== null) {
            $this->renderDenied();

            return;
        }
        $this->renderPage();
    }

    /** Renders the 403 / 404 page inside the shop layout with the right HTTP status. */
    protected function renderDenied(): void
    {
        [$status, $reason, $message] = $this->denied ?? [403, 'forbidden', 'Forbidden'];
        http_response_code($status);
        $this->context->smarty->assign(['bxmpv_denied' => ['status' => $status, 'reason' => $reason, 'message' => $message]]);
        $this->setTemplate('module:bxmpvendorportal/views/templates/front/denied.tpl');
    }

    abstract protected function renderPage(): void;

    protected function assignCommon(): void
    {
        $nav = [];
        foreach (Bxmpvendorportal::PAGES as $controller => [$label, $perm, $icon]) {
            if ($this->membership === null || !RolePolicy::can($this->role, $perm)) {
                continue;
            }
            if ($controller === 'csv' && !Module::isEnabled('bxmpcsv')) {
                continue;
            }
            $nav[] = ['controller' => $controller, 'label' => $label, 'icon' => $icon,
                'url' => $this->context->link->getModuleLink('bxmpvendorportal', $controller), 'current' => $controller === $this->portalPage];
        }
        $this->context->smarty->assign([
            'bxmpv' => [
                'page' => $this->portalPage,
                'nav' => $nav,
                'vendor_name' => $this->membership['vendor_name'] ?? '',
                'id_vendor' => $this->idVendor,
                'role' => $this->role,
                'can' => $this->membership ? RolePolicy::flagsFor($this->role) : [],
                'csrf' => $this->membership ? $this->csrfToken() : '',
                'currency_sign' => $this->context->currency->sign ?? '€',
                'customer_name' => trim($this->context->customer->firstname . ' ' . $this->context->customer->lastname),
            ],
        ]);
    }

    protected function url(string $controller, array $params = []): string
    {
        return $this->context->link->getModuleLink('bxmpvendorportal', $controller, $params);
    }

    /** PRG: redirect back with flash notifications. */
    protected function back(array $params = [], ?string $controller = null): void
    {
        $this->redirectWithNotifications($this->url($controller ?? $this->portalPage, $params));
    }

    protected function pageSize(): int
    {
        $n = (int) Configuration::get('BXMPV_PAGE_SIZE');

        return $n >= 5 && $n <= 200 ? $n : 20;
    }

    /** @return array{page:int,pages:int,total:int,offset:int,size:int} */
    protected function pagination(int $total): array
    {
        $size = $this->pageSize();
        $pages = max(1, (int) ceil($total / $size));
        $page = min($pages, max(1, (int) Tools::getValue('p', 1)));

        return ['page' => $page, 'pages' => $pages, 'total' => $total, 'offset' => ($page - 1) * $size, 'size' => $size];
    }

    /** Adds prev / next / numbered page links (window of ±2) to a pagination array. */
    protected function withLinks(array $pg, array $params): array
    {
        unset($params['p']);
        $link = fn (int $n) => $this->url($this->portalPage, $params + ($n > 1 ? ['p' => $n] : []));
        $pg['links'] = [];
        for ($n = max(1, $pg['page'] - 2); $n <= min($pg['pages'], $pg['page'] + 2); ++$n) {
            $pg['links'][] = ['n' => $n, 'url' => $link($n), 'current' => $n === $pg['page']];
        }
        $pg['prev_url'] = $pg['page'] > 1 ? $link($pg['page'] - 1) : null;
        $pg['next_url'] = $pg['page'] < $pg['pages'] ? $link($pg['page'] + 1) : null;
        $pg['from'] = $pg['total'] === 0 ? 0 : $pg['offset'] + 1;
        $pg['to'] = min($pg['total'], $pg['offset'] + $pg['size']);

        return $pg;
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = ['title' => $this->trans('Vendor portal', [], 'Modules.Bxmpvendorportal.Shop'), 'url' => $this->url('dashboard')];
        $label = Bxmpvendorportal::PAGES[$this->portalPage][0] ?? '';
        if ($this->portalPage !== 'dashboard' && $label !== '') {
            $breadcrumb['links'][] = ['title' => $label, 'url' => $this->url($this->portalPage)];
        }

        return $breadcrumb;
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['title'] = (Bxmpvendorportal::PAGES[$this->portalPage][0] ?? 'Vendor portal') . ' — Vendor portal';
        $page['meta']['robots'] = 'noindex';
        $page['body_classes']['bxmpv-portal'] = true;

        return $page;
    }

    protected function money(float $v): string
    {
        return Tools::getContextLocale($this->context)->formatPrice($v, $this->context->currency->iso_code);
    }

    /** Request helper: string from GET/POST trimmed & length-capped. */
    protected function str(string $key, int $max = 255): string
    {
        return mb_substr(trim((string) Tools::getValue($key, '')), 0, $max);
    }
}
