<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// ------------------------------------------------------------------
// API v1 — CORS applied globally; auth applied per group/route
// ------------------------------------------------------------------
$routes->group('api/v1', ['filter' => ['cors', 'ratelimit']], static function (RouteCollection $routes): void {

    // --- Auth (public) ---
    $routes->post('auth/login',    'Api\AuthController::login');
    $routes->post('auth/register', 'Api\AuthController::register');

    // --- Auth (protected) ---
    $routes->post('auth/logout',          'Api\AuthController::logout',         ['filter' => 'auth']);
    $routes->get('auth/me',               'Api\AuthController::me',             ['filter' => 'auth']);
    $routes->put('auth/profile',          'Api\AuthController::updateProfile',  ['filter' => 'auth']);
    $routes->put('auth/change-password',  'Api\AuthController::changePassword', ['filter' => 'auth']);

    // --- Team management (owner + admin) ---
    $routes->group('team', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',              'Api\TeamController::index');
        $routes->post('',             'Api\TeamController::create');
        $routes->patch('(:num)/role', 'Api\TeamController::updateRole/$1');
        $routes->delete('(:num)',     'Api\TeamController::delete/$1');
    });

    // Password reset (public — no auth needed)
    $routes->post('auth/forgot-password', 'Api\\AuthController::forgotPassword');
    $routes->post('auth/reset-password',  'Api\\AuthController::resetPassword');

    // --- Contacts (all protected) ---
    $routes->group('contacts', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',            'Api\ContactsController::index');
        $routes->get('export',      'Api\ContactsController::export');
        // Before the (:num) routes — a literal segment must not be read as an id.
        $routes->get('categories',  'Api\ContactsController::categories');
        $routes->post('',           'Api\ContactsController::create');
        $routes->get('(:num)',      'Api\ContactsController::show/$1');
        $routes->put('(:num)',      'Api\ContactsController::update/$1');
        $routes->delete('(:num)',   'Api\ContactsController::delete/$1');
        $routes->get('(:num)/quotations',     'Api\QuotesController::forContact/$1');
        $routes->get('(:num)/messaging',      'Api\ContactsController::messaging/$1');
        $routes->post('(:num)/send-template', 'Api\ContactsController::sendTemplate/$1');
        $routes->post('(:num)/tags/(:num)',   'Api\ContactsController::attachTag/$1/$2');
        $routes->delete('(:num)/tags/(:num)', 'Api\ContactsController::detachTag/$1/$2');
        $routes->get('(:num)/emails',         'Api\EmailsController::forContact/$1');
        $routes->post('(:num)/emails',        'Api\EmailsController::send/$1');
    });

    // --- CRM: per-tenant outbound email (SMTP) config ---
    $routes->get('email/config',  'Api\EmailsController::getConfig',  ['filter' => ['auth', 'role:owner,admin']]);
    $routes->post('email/config', 'Api\EmailsController::saveConfig', ['filter' => ['auth', 'role:owner,admin']]);
    $routes->post('email/config/test', 'Api\EmailsController::testConfig', ['filter' => ['auth', 'role:owner,admin']]);

    // --- Email marketing: templates, bulk campaigns, reports, suppression list ---
    $routes->group('email-marketing', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('overview',        'Api\EmailMarketingController::overview');
        $routes->post('audience-count', 'Api\EmailMarketingController::audienceCount');
        $routes->post('preview',        'Api\EmailMarketingController::preview');
        $routes->post('test',           'Api\EmailMarketingController::testSend');

        $routes->get('templates',            'Api\EmailMarketingController::templates');
        $routes->post('templates',           'Api\EmailMarketingController::createTemplate');
        $routes->get('templates/(:num)',     'Api\EmailMarketingController::showTemplate/$1');
        $routes->put('templates/(:num)',     'Api\EmailMarketingController::updateTemplate/$1');
        $routes->delete('templates/(:num)',  'Api\EmailMarketingController::deleteTemplate/$1');

        $routes->get('campaigns',                     'Api\EmailMarketingController::campaigns');
        $routes->post('campaigns',                    'Api\EmailMarketingController::createCampaign');
        $routes->get('campaigns/(:num)',              'Api\EmailMarketingController::showCampaign/$1');
        $routes->put('campaigns/(:num)',              'Api\EmailMarketingController::updateCampaign/$1');
        $routes->delete('campaigns/(:num)',           'Api\EmailMarketingController::deleteCampaign/$1');
        $routes->post('campaigns/(:num)/duplicate',   'Api\EmailMarketingController::duplicateCampaign/$1');
        $routes->post('campaigns/(:num)/send',        'Api\EmailMarketingController::send/$1');
        $routes->post('campaigns/(:num)/schedule',    'Api\EmailMarketingController::schedule/$1');
        $routes->post('campaigns/(:num)/unschedule',  'Api\EmailMarketingController::unschedule/$1');
        $routes->post('campaigns/(:num)/pause',       'Api\EmailMarketingController::pause/$1');
        $routes->post('campaigns/(:num)/resume',      'Api\EmailMarketingController::resume/$1');
        $routes->post('campaigns/(:num)/cancel',      'Api\EmailMarketingController::cancel/$1');
        $routes->get('campaigns/(:num)/recipients/export', 'Api\EmailMarketingController::exportRecipients/$1');
        $routes->get('campaigns/(:num)/recipients',   'Api\EmailMarketingController::recipients/$1');
        $routes->post('campaigns/(:num)/sequence',    'Api\EmailMarketingController::sequence/$1');

        $routes->get('suppressions',            'Api\EmailMarketingController::suppressions');
        $routes->post('suppressions',           'Api\EmailMarketingController::addSuppressions');
        $routes->delete('suppressions/(:num)',  'Api\EmailMarketingController::removeSuppression/$1', ['filter' => ['auth', 'role:owner,admin']]);
    });

    // --- CRM: Accounts (companies) ---
    $routes->group('accounts', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\AccountsController::index');
        $routes->post('',         'Api\AccountsController::create');
        $routes->get('(:num)',    'Api\AccountsController::show/$1');
        $routes->put('(:num)',    'Api\AccountsController::update/$1');
        $routes->delete('(:num)', 'Api\AccountsController::delete/$1');
    });

    // --- CRM: Tasks ---
    $routes->group('tasks', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',              'Api\TasksController::index');
        $routes->post('',             'Api\TasksController::create');
        $routes->put('(:num)',        'Api\TasksController::update/$1');
        $routes->post('(:num)/complete', 'Api\TasksController::complete/$1');
        $routes->delete('(:num)',     'Api\TasksController::delete/$1');
    });

    $routes->group('meetings', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\MeetingsController::index');
        $routes->get('(:num)',    'Api\MeetingsController::show/$1');
        $routes->post('',         'Api\MeetingsController::create');
        $routes->put('(:num)',    'Api\MeetingsController::update/$1');
        $routes->delete('(:num)', 'Api\MeetingsController::delete/$1');
    });

    // --- CRM: Tickets (service) ---
    $routes->group('tickets', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',           'Api\TicketsController::index');
        $routes->post('',          'Api\TicketsController::create');
        $routes->get('(:num)',     'Api\TicketsController::show/$1');
        $routes->put('(:num)',     'Api\TicketsController::update/$1');
        $routes->post('(:num)/status', 'Api\TicketsController::setStatus/$1');
        $routes->delete('(:num)',  'Api\TicketsController::delete/$1');
    });

    // --- CRM: Notes ---
    $routes->group('notes', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\NotesController::index');
        $routes->post('',         'Api\NotesController::create');
        $routes->put('(:num)',    'Api\NotesController::update/$1');
        $routes->delete('(:num)', 'Api\NotesController::delete/$1');
    });

    // --- CRM: Dashboard (reporting) ---
    $routes->get('crm/dashboard', 'Api\CrmDashboardController::index', ['filter' => 'auth']);
    $routes->get('home', 'Api\HomeDashboardController::index', ['filter' => 'auth']);
    $routes->get('home/targets', 'Api\HomeDashboardController::targets', ['filter' => 'auth']);   // lean version for the phone app   // travel bird's-eye dashboard (agents: own numbers only)

    // --- CRM: Saved views + unified filterable list (Phase G) ---
    $routes->group('crm/views', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\SavedViewsController::index');
        $routes->post('',         'Api\SavedViewsController::create');
        $routes->put('(:num)',    'Api\SavedViewsController::update/$1');
        $routes->delete('(:num)', 'Api\SavedViewsController::delete/$1');
    });
    $routes->get('crm/list/([a-z_]+)/meta', 'Api\CrmListController::meta/$1', ['filter' => 'auth']);
    $routes->get('crm/export/([a-z_]+)',    'Api\CrmListController::export/$1', ['filter' => 'auth']);
    $routes->post('crm/imports',                  'Api\CrmImportsController::create',       ['filter' => 'auth']);
    $routes->post('crm/imports/(:num)/mapping',   'Api\CrmImportsController::mapping/$1',   ['filter' => 'auth']);
    $routes->post('crm/imports/(:num)/process',   'Api\CrmImportsController::process/$1',   ['filter' => 'auth']);
    $routes->get('crm/duplicates/([a-z_]+)', 'Api\DedupeController::duplicates/$1', ['filter' => 'auth']);
    $routes->post('crm/merge/([a-z_]+)',     'Api\DedupeController::merge/$1',      ['filter' => 'auth']);
    $routes->get('crm/scoring-rules',  'Api\ScoringController::index', ['filter' => 'auth']);
    $routes->put('crm/scoring-rules',  'Api\ScoringController::save',  ['filter' => 'auth']);
    $routes->post('crm/scoring/recalc','Api\ScoringController::recalc', ['filter' => 'auth']);
    $routes->get('crm/assignment-rules',         'Api\AssignmentRulesController::index',     ['filter' => 'auth']);
    $routes->post('crm/assignment-rules',        'Api\AssignmentRulesController::create',    ['filter' => 'auth']);
    $routes->put('crm/assignment-rules/(:num)',  'Api\AssignmentRulesController::update/$1', ['filter' => 'auth']);
    $routes->delete('crm/assignment-rules/(:num)','Api\AssignmentRulesController::delete/$1', ['filter' => 'auth']);
    $routes->get('crm/business-hours', 'Api\BusinessHoursController::index', ['filter' => 'auth']);
    $routes->put('crm/business-hours', 'Api\BusinessHoursController::save',  ['filter' => 'auth']);
    // Reporting (Phase I)
    $routes->get('crm/dashboards',                  'Api\DashboardsController::index',  ['filter' => 'auth']);
    $routes->post('crm/dashboards',                 'Api\DashboardsController::create', ['filter' => 'auth']);
    $routes->put('crm/dashboards/(:num)',           'Api\DashboardsController::update/$1', ['filter' => 'auth']);
    $routes->delete('crm/dashboards/(:num)',        'Api\DashboardsController::delete/$1', ['filter' => 'auth']);
    $routes->post('crm/dashboards/(:num)/widgets',  'Api\DashboardsController::addWidget/$1', ['filter' => 'auth']);
    $routes->put('crm/widgets/(:num)',              'Api\DashboardsController::updateWidget/$1', ['filter' => 'auth']);
    $routes->delete('crm/widgets/(:num)',           'Api\DashboardsController::deleteWidget/$1', ['filter' => 'auth']);
    $routes->post('crm/reports/run',                'Api\ReportsController::run',     ['filter' => 'auth']);
    $routes->post('crm/reports/run-batch',          'Api\ReportsController::runBatch', ['filter' => 'auth']);
    $routes->get('crm/reports/options',             'Api\ReportsController::options', ['filter' => 'auth']);
    $routes->get('crm/reports/export',              'Api\ReportsController::export',  ['filter' => 'auth']);
    $routes->get('crm/forecast',             'Api\ForecastController::index',       ['filter' => 'auth']);
    $routes->get('crm/forecast/attainment',  'Api\ForecastController::attainment',  ['filter' => 'auth']);
    $routes->get('crm/sales-targets',        'Api\SalesTargetsController::index',   ['filter' => 'auth']);
    $routes->post('crm/sales-targets',       'Api\SalesTargetsController::create',  ['filter' => 'auth']);
    $routes->put('crm/sales-targets/(:num)', 'Api\SalesTargetsController::update/$1',['filter' => 'auth']);
    $routes->delete('crm/sales-targets/(:num)','Api\SalesTargetsController::delete/$1',['filter' => 'auth']);
    $routes->get('crm/leaderboard', 'Api\LeaderboardController::index', ['filter' => 'auth']);
    $routes->get('crm/price-books',                'Api\PriceBooksController::index',        ['filter' => 'auth']);
    $routes->post('crm/price-books',               'Api\PriceBooksController::create',       ['filter' => 'auth']);
    $routes->put('crm/price-books/(:num)',         'Api\PriceBooksController::update/$1',     ['filter' => 'auth']);
    $routes->delete('crm/price-books/(:num)',      'Api\PriceBooksController::delete/$1',     ['filter' => 'auth']);
    $routes->post('crm/price-books/(:num)/entries','Api\PriceBooksController::setEntry/$1',   ['filter' => 'auth']);
    $routes->delete('crm/price-book-entries/(:num)','Api\PriceBooksController::deleteEntry/$1',['filter' => 'auth']);
    $routes->get('crm/preferences', 'Api\PreferencesController::index', ['filter' => 'auth']);
    $routes->put('crm/preferences', 'Api\PreferencesController::save',  ['filter' => 'auth']);
    $routes->get('crm/audit-logs', 'Api\AuditLogsController::index', ['filter' => 'auth']);
    $routes->get('crm/plan-usage', 'Api\PlanUsageController::index', ['filter' => 'auth']);
    $routes->get('crm/teams',                       'Api\TeamsController::index',        ['filter' => 'auth']);
    $routes->post('crm/teams',                      'Api\TeamsController::create',       ['filter' => 'auth']);
    $routes->put('crm/teams/(:num)',                'Api\TeamsController::update/$1',     ['filter' => 'auth']);
    $routes->delete('crm/teams/(:num)',             'Api\TeamsController::delete/$1',     ['filter' => 'auth']);
    $routes->post('crm/teams/(:num)/members',       'Api\TeamsController::addMember/$1',  ['filter' => 'auth']);
    $routes->delete('crm/teams/(:num)/members/(:num)','Api\TeamsController::removeMember/$1/$2', ['filter' => 'auth']);
    $routes->get('crm/list/([a-z_]+)',      'Api\CrmListController::list/$1', ['filter' => 'auth']);
    $routes->post('crm/bulk/([a-z_]+)',     'Api\CrmBulkController::apply/$1', ['filter' => 'auth']);
    $routes->get('crm/search', 'Api\CrmSearchController::index', ['filter' => 'auth']);
    $routes->get('crm/recycle/([a-z_]+)',          'Api\RecycleBinController::index/$1',   ['filter' => 'auth']);
    $routes->post('crm/recycle/([a-z_]+)/restore', 'Api\RecycleBinController::restore/$1', ['filter' => 'auth']);
    $routes->post('crm/recycle/([a-z_]+)/purge',   'Api\RecycleBinController::purge/$1',   ['filter' => 'auth']);

    // --- CRM: Custom objects + records (multi-industry) ---
    $routes->group('custom-objects', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\CustomObjectsController::index');
        $routes->post('',         'Api\CustomObjectsController::create');
        $routes->get('(:num)',    'Api\CustomObjectsController::show/$1');
        $routes->put('(:num)',    'Api\CustomObjectsController::update/$1');
        $routes->delete('(:num)', 'Api\CustomObjectsController::delete/$1');
        $routes->post('(:num)/fields',  'Api\CustomObjectsController::addField/$1');
        $routes->get('(:num)/records',  'Api\CustomObjectRecordsController::listForObject/$1');
        $routes->post('(:num)/records', 'Api\CustomObjectRecordsController::create/$1');
    });
    $routes->delete('custom-object-fields/(:num)', 'Api\CustomObjectsController::deleteField/$1', ['filter' => 'auth']);
    $routes->group('custom-object-records', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('(:num)',    'Api\CustomObjectRecordsController::show/$1');
        $routes->put('(:num)',    'Api\CustomObjectRecordsController::update/$1');
        $routes->delete('(:num)', 'Api\CustomObjectRecordsController::delete/$1');
    });

    // --- CRM: Associations (any↔any links) ---
    $routes->group('associations', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\AssociationsController::index');
        $routes->post('',         'Api\AssociationsController::create');
        $routes->delete('(:num)', 'Api\AssociationsController::delete/$1');
    });

    // --- CRM: Industry templates ---
    $routes->group('industry-templates', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',              'Api\IndustryTemplatesController::index');
        $routes->post('([a-z_]+)/apply', 'Api\IndustryTemplatesController::apply/$1');
    });

    // --- CRM: Activities + unified record timeline ---
    $routes->group('activities', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->post('', 'Api\ActivitiesController::create');
    });
    $routes->get('timeline', 'Api\ActivitiesController::timeline', ['filter' => 'auth']);

    // --- CRM: Pipelines (deal/ticket workflows) ---
    $routes->group('pipelines', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',       'Api\PipelinesController::index');
        $routes->put('stages/(:num)', 'Api\PipelinesController::updateStage/$1');
        $routes->get('(:num)', 'Api\PipelinesController::show/$1');
    });

    // --- CRM: Deals + kanban board ---
    $routes->group('deals', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('board',  'Api\DealsController::board');
        $routes->post('',      'Api\DealsController::create');
        $routes->get('(:num)', 'Api\DealsController::show/$1');
        $routes->put('(:num)', 'Api\DealsController::update/$1');
        $routes->post('(:num)/move', 'Api\DealsController::move/$1');
        $routes->delete('(:num)', 'Api\DealsController::delete/$1');
        $routes->post('(:num)/line-items', 'Api\DealsController::addLineItem/$1');
        $routes->delete('(:num)/line-items/(:num)', 'Api\DealsController::deleteLineItem/$1/$2');
        $routes->get('(:num)/quotes',  'Api\QuotesController::forDeal/$1');
        $routes->post('(:num)/quotes', 'Api\QuotesController::createFromDeal/$1');
    });

    // --- Travel vertical ---
    $routes->group('travel', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->group('rate-import', ['filter' => 'role:owner,admin'], static function (RouteCollection $routes): void {
            $routes->post('preview',          'Api\RateImportController::preview');
            $routes->get('(:num)',            'Api\RateImportController::show/$1');
            $routes->post('(:num)/commit',    'Api\RateImportController::commit/$1');
        });
        $routes->get('reports/ads', 'Api\TravelReportsController::ads');
        $routes->get('(:segment)',            'Api\TravelMasterController::listAll/$1');
        $routes->post('(:segment)',           'Api\TravelMasterController::store/$1');
        $routes->get('(:segment)/(:num)',     'Api\TravelMasterController::one/$1/$2');
        $routes->put('(:segment)/(:num)',     'Api\TravelMasterController::save/$1/$2');
        $routes->delete('(:segment)/(:num)',  'Api\TravelMasterController::destroy/$1/$2');
    });
    $routes->group('trips', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->post('ai-parse',         'Api\TripsController::aiParse');
        $routes->get('',                  'Api\TripsController::index');
        $routes->post('',                 'Api\TripsController::create');
        $routes->get('(:num)',            'Api\TripsController::show/$1');
        $routes->put('(:num)',            'Api\TripsController::update/$1');
        $routes->post('(:num)/status',    'Api\TripsController::setStatus/$1');
        $routes->post('(:num)/ai-itinerary', 'Api\TripsController::aiItinerary/$1');
        $routes->post('quick',               'Api\TripsController::quick');
    });
    $routes->group('itineraries', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',                          'Api\ItinerariesController::index');
        $routes->post('',                         'Api\ItinerariesController::create');
        $routes->get('(:num)',                    'Api\ItinerariesController::show/$1');
        $routes->put('(:num)',                    'Api\ItinerariesController::update/$1');
        $routes->delete('(:num)',                 'Api\ItinerariesController::delete/$1');
        $routes->post('(:num)/days',              'Api\ItinerariesController::addDay/$1');
        $routes->post('(:num)/items',             'Api\ItinerariesController::addItem/$1');
        $routes->delete('(:num)/items/(:num)',    'Api\ItinerariesController::deleteItem/$1/$2');
        $routes->post('(:num)/version',           'Api\ItinerariesController::newVersion/$1');
        $routes->post('(:num)/relock-fx',         'Api\ItinerariesController::relockFx/$1');
        $routes->post('(:num)/share',             'Api\ItinerariesController::share/$1');
    });
    $routes->group('ad-platforms', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',                          'Api\AdPlatformsController::status');
        $routes->put('meta',                      'Api\AdPlatformsController::saveMeta');
        $routes->post('meta/test',                'Api\AdPlatformsController::testMeta');
        $routes->post('google/start',             'Api\AdPlatformsController::googleStart');
        $routes->get('google/customers',          'Api\AdPlatformsController::googleCustomers');
        $routes->put('google/account',            'Api\AdPlatformsController::googleAccount');
        $routes->get('google/actions',            'Api\AdPlatformsController::googleActions');
        $routes->put('google/mapping',            'Api\AdPlatformsController::googleMapping');
        $routes->get('deliveries',                'Api\AdPlatformsController::deliveries');
        $routes->post('deliveries/(:num)/retry',  'Api\AdPlatformsController::retry/$1');
        $routes->delete('(:segment)',             'Api\AdPlatformsController::disconnect/$1');
    });
    // Google redirects the browser here after consent — public; the single-use state is the credential.
    $routes->get('google-ads/oauth/callback', 'Public\GoogleAdsOauthCallbackController::index');

    $routes->group('travel-automations', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',         'Api\TravelAutomationsController::index');
        $routes->post('install', 'Api\TravelAutomationsController::install', ['filter' => 'role:owner,admin']);
    });
    $routes->group('ad-campaigns', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',                    'Api\AdCampaignsController::index');
        $routes->get('connection',          'Api\AdCampaignsController::connection');
        $routes->get('settings',            'Api\AdCampaignsController::settings');
        $routes->put('settings',            'Api\AdCampaignsController::saveSettings');
        $routes->get('meta/lead-forms',     'Api\AdCampaignsController::leadForms');
        $routes->get('meta/geo',            'Api\AdCampaignsController::metaGeo');
        $routes->get('google/geo',          'Api\AdCampaignsController::googleGeo');
        $routes->get('assets',              'Api\AdCampaignsController::assets');
        $routes->post('assets',             'Api\AdCampaignsController::uploadAsset');
        $routes->get('assets/(:num)/file',  'Api\AdCampaignsController::assetFile/$1');
        $routes->delete('assets/(:num)',    'Api\AdCampaignsController::deleteAsset/$1');
        $routes->get('audiences',           'Api\AdCampaignsController::audiences');
        $routes->post('audiences',          'Api\AdCampaignsController::createAudience');
        $routes->post('audiences/lookalike', 'Api\AdCampaignsController::createLookalike');
        $routes->post('audiences/(:num)/refresh',  'Api\AdCampaignsController::refreshAudience/$1');
        $routes->get('audiences/(:num)/estimate',  'Api\AdCampaignsController::audienceEstimate/$1');
        $routes->delete('audiences/(:num)', 'Api\AdCampaignsController::deleteAudience/$1');
        $routes->post('preview',            'Api\AdCampaignsController::preview');
        $routes->post('',                   'Api\AdCampaignsController::create');
        $routes->post('sync',               'Api\AdCampaignsController::sync');
        $routes->post('ai-copy',            'Api\AdCampaignsController::aiCopy');
        $routes->get('rules',               'Api\AdCampaignsController::rules');
        $routes->post('rules',              'Api\AdCampaignsController::saveRule');
        $routes->put('rules/(:num)',        'Api\AdCampaignsController::saveRule/$1');
        $routes->delete('rules/(:num)',     'Api\AdCampaignsController::deleteRule/$1');
        $routes->post('(:num)/launch',      'Api\AdCampaignsController::launch/$1');
        $routes->post('(:num)/pause',       'Api\AdCampaignsController::pause/$1');
        $routes->put('(:num)/budget',       'Api\AdCampaignsController::budget/$1');
        $routes->get('(:num)/history',      'Api\AdCampaignsController::history/$1');
    });
    $routes->group('billing-docs', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('profile',                       'Api\BillingDocsController::profile');
        $routes->put('profile',                       'Api\BillingDocsController::saveProfile', ['filter' => 'role:owner,admin']);
        $routes->get('itineraries/(:num)/pdf',        'Api\BillingDocsController::quotePdf/$1');
        $routes->get('bookings/(:num)/documents',     'Api\BillingDocsController::forBooking/$1');
        $routes->post('bookings/(:num)/invoice',      'Api\BillingDocsController::issue/$1',      ['filter' => 'role:owner,admin']);
        $routes->post('invoices/(:num)/einvoice/cancel', 'Api\BillingDocsController::cancelEinvoice/$1', ['filter' => 'role:owner,admin']);
        $routes->post('invoices/(:num)/credit-note',  'Api\BillingDocsController::creditNote/$1', ['filter' => 'role:owner,admin']);
        $routes->get('invoices/(:num)/pdf',           'Api\BillingDocsController::invoicePdf/$1');
        $routes->post('payments/(:num)/receipt',      'Api\BillingDocsController::receipt/$1');
        $routes->get('exports/summary',               'Api\BillingDocsController::exportSummary',  ['filter' => 'role:owner,admin']);
        $routes->get('gst/3b',                        'Api\BillingDocsController::gst3b',          ['filter' => 'role:owner,admin']);
        $routes->get('gst/itc',                       'Api\BillingDocsController::gstItc',         ['filter' => 'role:owner,admin']);
        $routes->put('gst/itc',                       'Api\BillingDocsController::saveGstItc',     ['filter' => 'role:owner,admin']);
        $routes->get('gst/hsn',                       'Api\BillingDocsController::gstHsn',         ['filter' => 'role:owner,admin']);
        $routes->get('gst/filings',                   'Api\BillingDocsController::gstFilings',     ['filter' => 'role:owner,admin']);
        $routes->post('gst/filings',                  'Api\BillingDocsController::recordGstFiling', ['filter' => 'role:owner,admin']);
        $routes->delete('gst/filings',                'Api\BillingDocsController::removeGstFiling', ['filter' => 'role:owner,admin']);
        $routes->get('exports/download',              'Api\BillingDocsController::exportDownload', ['filter' => 'role:owner,admin']);
        $routes->get('tcs/report',                    'Api\BillingDocsController::tcsReport',        ['filter' => 'role:owner,admin']);
        $routes->get('tcs/download',                  'Api\BillingDocsController::tcsDownload',      ['filter' => 'role:owner,admin']);
        $routes->post('tcs/challans',                 'Api\BillingDocsController::tcsAddChallan',    ['filter' => 'role:owner,admin']);
        $routes->delete('tcs/challans/(:num)',        'Api\BillingDocsController::tcsDeleteChallan/$1', ['filter' => 'role:owner,admin']);
        $routes->put('bookings/(:num)/pan',           'Api\BillingDocsController::setPan/$1',        ['filter' => 'role:owner,admin']);
        $routes->post('send',                         'Api\BillingDocsController::sendWhatsApp');
        $routes->post('whatsapp-template',            'Api\BillingDocsController::setupTemplate', ['filter' => 'role:owner,admin']);
    });
    // Customer-facing downloads (token in the link is the credential).
    $routes->get('public/quotes/(:alphanum)/pdf',     'Public\\PublicDocumentsController::quote/$1');
    $routes->get('public/invoices/(:alphanum)/pdf',   'Public\\PublicDocumentsController::invoice/$1');
    $routes->get('public/vouchers/(:alphanum)/pdf',   'Public\\PublicDocumentsController::voucher/$1');

    $routes->group('mobile', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->post('devices',        'Api\MobilePushController::registerDevice');
        $routes->delete('devices',      'Api\MobilePushController::unregisterDevice');
        $routes->get('devices',         'Api\MobilePushController::devices');
        $routes->get('push/preferences', 'Api\MobilePushController::preferences');
        $routes->put('push/preferences', 'Api\MobilePushController::savePreferences');
        $routes->post('push/test',      'Api\MobilePushController::test');
        $routes->get('push/log',        'Api\MobilePushController::log');
    });
    $routes->group('dunning', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('settings',         'Api\DunningController::settings');
        $routes->put('settings',         'Api\DunningController::save', ['filter' => 'role:owner,admin']);
        $routes->post('setup-templates', 'Api\DunningController::setupTemplates', ['filter' => 'role:owner,admin']);
    });
    $routes->group('payables', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',                      'Api\SupplierPayablesController::index');
        $routes->get('services/(:num)',       'Api\SupplierPayablesController::service/$1');
        $routes->post('services/(:num)/pay',  'Api\SupplierPayablesController::pay/$1');
        $routes->delete('payments/(:num)',    'Api\SupplierPayablesController::remove/$1');
    });
    $routes->get('travel-reports', 'Api\TravelReportsController::index', ['filter' => ['auth', 'role:owner,admin']]);
    // Customer portal (public: the token is the credential) + staff review.
    $routes->get('public/portal/(:alphanum)',                           'Public\PortalController::show/$1');
    $routes->post('public/portal/(:alphanum)/payments/(:num)/link',     'Public\PortalController::payLink/$1/$2');
    $routes->post('public/portal/(:alphanum)/items/(:num)/upload',      'Public\PortalController::upload/$1/$2');
    $routes->group('portal', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->post('bookings/(:num)/link',     'Api\PortalAdminController::link/$1');
        $routes->post('bookings/(:num)/rotate',   'Api\PortalAdminController::rotate/$1', ['filter' => 'role:owner,admin']);
        $routes->get('items/(:num)/uploads',      'Api\PortalAdminController::uploads/$1');
        $routes->get('uploads/(:num)/file',       'Api\PortalAdminController::download/$1');
        $routes->post('uploads/(:num)/review',    'Api\PortalAdminController::review/$1');
    });
    $routes->group('departures', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',                          'Api\DeparturesController::index');
        $routes->post('',                         'Api\DeparturesController::create',      ['filter' => 'role:owner,admin']);
        $routes->get('(:num)',                    'Api\DeparturesController::show/$1');
        $routes->put('(:num)',                    'Api\DeparturesController::update/$1',   ['filter' => 'role:owner,admin']);
        $routes->post('(:num)/status',            'Api\DeparturesController::status/$1',   ['filter' => 'role:owner,admin']);
        $routes->post('(:num)/reserve',           'Api\DeparturesController::reserve/$1');
        $routes->get('(:num)/manifest',           'Api\DeparturesController::manifest/$1');
        $routes->get('(:num)/manifest.csv',       'Api\DeparturesController::manifestCsv/$1');
        $routes->delete('holds/(:num)',           'Api\DeparturesController::release/$1');
    });
    // Website contact form (public, throttled; see Public\ContactController)
    $routes->post('public/contact', 'Public\ContactController::submit');
    $routes->options('public/contact', static function (): void {});   // CORS preflight from the marketing site (origins come from CORS_ORIGIN)
    // Website live chat (visitor side) + CORS preflight for the marketing site
    $routes->post('public/chat/start',                 'Public\ChatController::start');
    $routes->post('public/chat/(:alphanum)/send',      'Public\ChatController::send/$1');
    $routes->get('public/chat/(:alphanum)/poll',       'Public\ChatController::poll/$1');
    $routes->options('public/chat/(:any)',             static function (): void {});
    // Platform (TripSarthi team) admin: every customer workspace, subscriptions, website enquiries and live chat
    // Separate admin app (admin.tripsarthi.com): its own login + session store; customer-app tokens are NOT accepted under /admin
    $routes->post('admin-auth/login',  'Api\AdminAuthController::login');
    $routes->get('admin-auth/me',      'Api\AdminAuthController::me',     ['filter' => 'adminauth']);
    $routes->post('admin-auth/logout', 'Api\AdminAuthController::logout', ['filter' => 'adminauth']);
    $routes->group('admin', ['filter' => 'adminauth'], static function (RouteCollection $routes): void {
        $routes->get('overview',                   'Api\PlatformAdminController::overview');
        $routes->get('tenants',                    'Api\PlatformAdminController::tenants');
        $routes->get('tenants/(:num)',             'Api\PlatformAdminController::tenant/$1');
        $routes->put('tenants/(:num)/plan',        'Api\PlatformAdminController::setPlan/$1');
        $routes->post('tenants/(:num)/status',     'Api\PlatformAdminController::setStatus/$1');
        $routes->get('subscriptions',              'Api\PlatformAdminController::subscriptions');
        $routes->get('enquiries',                  'Api\PlatformAdminController::enquiries');
        $routes->put('enquiries/(:num)',           'Api\PlatformAdminController::enquiryStatus/$1');
        $routes->get('chats',                      'Api\PlatformAdminController::chats');
        $routes->get('chats/(:num)',               'Api\PlatformAdminController::chat/$1');
        $routes->post('chats/(:num)/reply',        'Api\PlatformAdminController::chatReply/$1');
        $routes->post('chats/(:num)/close',        'Api\PlatformAdminController::chatClose/$1');
    });
    // Travel-portal / aggregator leads: public webhook (token = credential) + owner/admin management.
    $routes->post('public/lead-sources/(:alphanum)', 'Public\LeadSourceController::receive/$1');
    $routes->group('lead-sources', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',                      'Api\LeadSourcesController::index');
        $routes->post('',                     'Api\LeadSourcesController::create');
        $routes->put('(:num)',                'Api\LeadSourcesController::update/$1');
        $routes->post('(:num)/rotate',        'Api\LeadSourcesController::rotate/$1');
        $routes->get('(:num)/events',         'Api\LeadSourcesController::events/$1');
        $routes->delete('(:num)',             'Api\LeadSourcesController::remove/$1');
    });
    $routes->group('targets', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('', 'Api\TravelTargetsController::index');
        $routes->put('', 'Api\TravelTargetsController::save');
    });
    $routes->group('fx', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',                      'Api\FxController::index');
        $routes->post('',                     'Api\FxController::add',     ['filter' => 'role:owner,admin']);
        $routes->post('refresh',              'Api\FxController::refresh', ['filter' => 'role:owner,admin']);
        $routes->put('(:segment)',            'Api\FxController::update/$1', ['filter' => 'role:owner,admin']);
        $routes->post('(:segment)/auto',      'Api\FxController::auto/$1', ['filter' => 'role:owner,admin']);
        $routes->get('(:segment)/history',    'Api\FxController::history/$1');
        $routes->delete('(:segment)',         'Api\FxController::remove/$1', ['filter' => 'role:owner,admin']);
    });
    $routes->group('einvoice', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('settings', 'Api\EinvoiceController::settings');
        $routes->put('settings', 'Api\EinvoiceController::save');
        $routes->post('test',    'Api\EinvoiceController::test');
    });
    $routes->group('checklists', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('overview',            'Api\ChecklistsController::overview');
        $routes->get('bookings/(:num)',     'Api\ChecklistsController::show/$1');
        $routes->post('bookings/(:num)',    'Api\ChecklistsController::generate/$1');
        $routes->patch('items/(:num)',      'Api\ChecklistsController::setStatus/$1');
    });
    $routes->group('bookings', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',                          'Api\BookingsController::index');
        $routes->get('dashboard',                 'Api\BookingsController::dashboard');
        $routes->post('',                         'Api\BookingsController::create');
        $routes->post('payments/(:num)/paid',     'Api\BookingsController::markPaid/$1');
        $routes->post('payments/(:num)/link',     'Api\DunningController::link/$1');
        $routes->post('payments/(:num)/remind',   'Api\DunningController::remind/$1');
        $routes->get('payments/(:num)/reminders', 'Api\DunningController::reminders/$1');
        $routes->patch('services/(:num)',         'Api\BookingsController::updateService/$1');
        $routes->get('(:num)',                    'Api\BookingsController::show/$1');
        $routes->post('(:num)/travelers',         'Api\BookingsController::addTraveler/$1');
        $routes->post('(:num)/cancel',            'Api\BookingsController::cancel/$1');
    });
    // Customer-facing quote link (public; the token is the credential).
    $routes->get('public/quotes/(:alphanum)',          'Public\ItineraryShareController::show/$1');
    $routes->post('public/quotes/(:alphanum)/accept',  'Public\ItineraryShareController::accept/$1');

    // --- CRM: Quotes (CPQ) ---
    $routes->group('quotes', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('(:num)',        'Api\QuotesController::show/$1');
        $routes->get('(:num)/pdf',    'Api\QuotesController::pdf/$1');
        $routes->post('(:num)/send',  'Api\QuotesController::send/$1');
        $routes->post('(:num)/send-email', 'Api\QuotesController::sendEmail/$1');
        $routes->post('(:num)/status', 'Api\QuotesController::setStatus/$1');
        $routes->delete('(:num)',     'Api\QuotesController::delete/$1');
    });

    // --- CRM: Document attachments ---
    $routes->group('documents', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',                'Api\DocumentsController::index');
        $routes->post('',               'Api\DocumentsController::create');
        $routes->get('(:num)/download', 'Api\DocumentsController::download/$1');
        $routes->delete('(:num)',       'Api\DocumentsController::delete/$1');
    });

    // --- Tags (all protected) ---
    $routes->group('tags', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\TagsController::index');
        $routes->post('',         'Api\TagsController::create');
        $routes->put('(:num)',    'Api\TagsController::update/$1');
        $routes->delete('(:num)', 'Api\TagsController::delete/$1');
    });

    // --- Custom Fields (all protected) ---
    $routes->group('custom-fields', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\CustomFieldsController::index');
        $routes->post('',         'Api\CustomFieldsController::create');
        $routes->put('(:num)',    'Api\CustomFieldsController::update/$1');
        $routes->delete('(:num)', 'Api\CustomFieldsController::delete/$1');
    });

    // --- Imports (all protected) ---
    $routes->group('imports', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',             'Api\ImportsController::index');
        $routes->post('',            'Api\ImportsController::upload');
        $routes->get('(:num)',       'Api\ImportsController::show/$1');
        $routes->post('(:num)/map',      'Api\ImportsController::map/$1');
        $routes->post('(:num)/start',    'Api\ImportsController::start/$1');
        $routes->post('(:num)/continue', 'Api\ImportsController::continue/$1');
    });

    // --- Web Forms (all protected) ---
    $routes->group('web-forms', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',             'Api\WebFormsController::index');
        $routes->post('',            'Api\WebFormsController::create');
        $routes->get('(:num)',       'Api\WebFormsController::show/$1');
        $routes->put('(:num)',       'Api\WebFormsController::update/$1');
        $routes->delete('(:num)',    'Api\WebFormsController::delete/$1');
        $routes->get('(:num)/snippet', 'Api\WebFormsController::snippet/$1');
    });

    // --- Flows (all protected) ---
    $routes->group('flows', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',                       'Api\FlowsController::index');
        $routes->post('',                      'Api\FlowsController::create');
        $routes->get('(:num)',                 'Api\FlowsController::show/$1');
        $routes->put('(:num)',                 'Api\FlowsController::update/$1');
        $routes->patch('(:num)/status',        'Api\FlowsController::setStatus/$1');
        $routes->delete('(:num)',              'Api\FlowsController::delete/$1');
        $routes->post('(:num)/test',           'Api\FlowsController::test/$1');
        $routes->get('(:num)/runs',            'Api\FlowsController::runs/$1');
        $routes->get('(:num)/runs/(:num)',     'Api\FlowsController::runDetail/$1/$2');
    });

    // --- Templates (all protected) ---
    $routes->group('templates', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',              'Api\TemplatesController::index');
        $routes->post('',             'Api\TemplatesController::create');
        $routes->get('(:num)',        'Api\TemplatesController::show/$1');
        $routes->put('(:num)',        'Api\TemplatesController::update/$1');
        $routes->delete('(:num)',     'Api\TemplatesController::delete/$1');
        $routes->post('(:num)/submit','Api\TemplatesController::submit/$1');
        $routes->post('(:num)/sync',  'Api\TemplatesController::sync/$1');
    });

    // --- Campaigns (all protected) ---
    $routes->group('campaigns', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',           'Api\CampaignsController::index');
        $routes->post('',          'Api\CampaignsController::create');
        $routes->get('(:num)',     'Api\CampaignsController::show/$1');
        $routes->put('(:num)',     'Api\CampaignsController::update/$1');
        $routes->delete('(:num)',  'Api\CampaignsController::delete/$1');
        $routes->post('(:num)/send', 'Api\CampaignsController::send/$1');
        $routes->post('(:num)/schedule',   'Api\CampaignsController::schedule/$1');
        $routes->post('(:num)/unschedule', 'Api\CampaignsController::unschedule/$1');
        $routes->get('(:num)/retarget-preview', 'Api\CampaignsController::retargetPreview/$1');
        $routes->post('(:num)/retarget',        'Api\CampaignsController::retarget/$1');
    });

    // --- Segments (all protected) ---
    $routes->group('segments', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',            'Api\\SegmentsController::index');
        $routes->post('',           'Api\\SegmentsController::create');
        $routes->get('(:num)',      'Api\\SegmentsController::show/$1');
        $routes->put('(:num)',      'Api\\SegmentsController::update/$1');
        $routes->delete('(:num)',   'Api\\SegmentsController::delete/$1');
        $routes->get('(:num)/count','Api\\SegmentsController::count/$1');
    });

    // --- Analytics (all protected) ---
    $routes->group('analytics', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('summary',   'Api\AnalyticsController::summary');
        $routes->get('overview',  'Api\AnalyticsController::overview');
        // Literal segment first: 'messages/export' must not be swallowed by a
        // future 'messages/(:segment)' route.
        $routes->get('messages/export', 'Api\AnalyticsController::messagesExport');
        $routes->get('messages',  'Api\AnalyticsController::messages');
        $routes->get('campaigns', 'Api\AnalyticsController::campaigns');
        $routes->get('campaigns/(:num)/clicks', 'Api\AnalyticsController::campaignClicks/$1');
        $routes->get('campaigns/(:num)/report', 'Api\AnalyticsController::campaignReport/$1');
        $routes->get('flows',     'Api\AnalyticsController::flows');
        $routes->get('billing',       'Api\AnalyticsController::billing');
        $routes->post('billing/sync', 'Api\AnalyticsController::billingSync');
        $routes->get('ad-spend',      'Api\AnalyticsController::adSpend');
    });

    // --- Billing / subscriptions (owner only — SaaS; controller gates via FeatureGate) ---
    $routes->group('billing', ['filter' => ['auth', 'role:owner']], static function (RouteCollection $routes): void {
        $routes->post('subscribe',       'Api\BillingController::subscribe');
        $routes->post('cancel',          'Api\BillingController::cancel');
        $routes->get('status',           'Api\BillingController::status');
        $routes->get('history',          'Api\BillingController::history');
        $routes->post('create-order',    'Api\BillingController::createOrder');
        $routes->post('verify-payment',  'Api\BillingController::verifyPayment');
    });

    // --- License (owner only — self_hosted; controller gates via FeatureGate) ---
    $routes->group('license', ['filter' => ['auth', 'role:owner']], static function (RouteCollection $routes): void {
        $routes->post('activate', 'Api\LicenseController::activate');
        $routes->get('status',    'Api\LicenseController::status');
    });

    // --- Meta Lead Ads integrations (owner + admin) ---
    $routes->group('meta-integrations', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',                    'Api\MetaIntegrationsController::index');
        $routes->post('',                   'Api\MetaIntegrationsController::create');
        $routes->delete('(:num)',           'Api\MetaIntegrationsController::delete/$1');
        $routes->post('link-page',          'Api\MetaIntegrationsController::linkPage');
        $routes->post('(:num)/subscribe',   'Api\MetaIntegrationsController::subscribe/$1');
        $routes->post('(:num)/test',        'Api\MetaIntegrationsController::test/$1');
    });

    // --- Social Planner (Facebook Page / Instagram Business scheduling) ---
    $routes->group('social', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('posts',           'Api\SocialController::posts');
        $routes->post('posts',          'Api\SocialController::createPost');
        $routes->delete('posts/(:num)', 'Api\SocialController::deletePost/$1');
    });
    $routes->group('social/media', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->post('', 'Api\SocialMediaController::upload');
    });
    $routes->group('social/accounts', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\SocialController::accounts');
        $routes->post('',         'Api\SocialController::connect');
        $routes->delete('(:num)', 'Api\SocialController::deleteAccount/$1');
    });
    // Facebook Login — the token never touches the browser, so this is the
    // preferred path; SocialController::connect stays for manual/self-hosted use.
    $routes->group('social/oauth', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('status',  'Api\SocialOAuthController::status');
        $routes->post('start',  'Api\SocialOAuthController::start');
        $routes->get('pages',   'Api\SocialOAuthController::pages');
        $routes->post('select', 'Api\SocialOAuthController::select');
    });

    // --- Meta lead archive: a month's leads from Meta, import into Contacts (owner + admin) ---
    $routes->group('meta-leads', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',        'Api\MetaLeadsController::month');
        $routes->post('import', 'Api\MetaLeadsController::import');
    });

    // --- Meta Ads spend (owner + admin) ---
    $routes->group('meta-ads', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\MetaAdsController::status');
        $routes->post('connect',  'Api\MetaAdsController::connect');
        $routes->get('accounts',  'Api\MetaAdsController::accounts');
        $routes->post('accounts', 'Api\MetaAdsController::select');
        $routes->post('sync',     'Api\MetaAdsController::sync');
        $routes->delete('',       'Api\MetaAdsController::disconnect');
    });

    // --- Google Ads Lead Forms integrations (owner + admin) ---
    $routes->group('google-integrations', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\GoogleIntegrationsController::index');
        $routes->post('',         'Api\GoogleIntegrationsController::create');
        $routes->delete('(:num)', 'Api\GoogleIntegrationsController::delete/$1');
        $routes->post('(:num)/test', 'Api\GoogleIntegrationsController::test/$1');
    });

    // --- IMAP inbound-email integration (owner + admin) ---
    $routes->group('imap-integrations', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\ImapIntegrationsController::index');
        $routes->post('',         'Api\ImapIntegrationsController::create');
        $routes->delete('(:num)', 'Api\ImapIntegrationsController::delete/$1');
    });

    // --- Settings / branding ---
    // Read: any authenticated tenant member — white-label applies app-wide, so
    // agents must see the brand too. Write: owner only.
    $routes->get('settings/branding',  'Api\\SettingsController::getBranding',    ['filter' => 'auth']);
    $routes->post('settings/branding', 'Api\\SettingsController::updateBranding', ['filter' => ['auth', 'role:owner']]);

    // --- Record-level permissions (Phase M) ---
    $routes->get('settings/record-visibility',  'Api\\SettingsController::getRecordVisibility',    ['filter' => 'auth']);
    $routes->post('settings/record-visibility', 'Api\\SettingsController::updateRecordVisibility', ['filter' => ['auth', 'role:owner,admin']]);
    $routes->get('crm/(:segment)/(:num)/shares',            'Api\\SharesController::listShares/$1/$2',  ['filter' => 'auth']);
    $routes->post('crm/(:segment)/(:num)/shares',           'Api\\SharesController::addShare/$1/$2',    ['filter' => 'auth']);
    $routes->delete('crm/(:segment)/(:num)/shares/(:num)',  'Api\\SharesController::removeShare/$1/$2/$3', ['filter' => 'auth']);

    // --- WABA account management (owner + admin) ---
    $routes->group('waba', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',               'Api\WabaController::show');
        $routes->post('',              'Api\WabaController::connect');
        $routes->get('providers',      'Api\WabaController::providers');
        $routes->get('test',           'Api\WabaController::testConnection');
        $routes->get('phone-numbers',  'Api\WabaController::phoneNumbers');
    });

    // --- Payments (Razorpay payment links) ---
    // Config is owner/admin; sending links is any authenticated agent.
    $routes->get('payments/config',  'Api\PaymentsController::getConfig',  ['filter' => ['auth', 'role:owner,admin']]);
    $routes->post('payments/config', 'Api\PaymentsController::saveConfig', ['filter' => ['auth', 'role:owner,admin']]);
    $routes->group('payments', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('links',  'Api\PaymentsController::index');
        $routes->post('links', 'Api\PaymentsController::create');
    });

    // --- E-commerce integrations (Shopify / WooCommerce) — owner + admin ---
    $routes->group('ecommerce', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('(:segment)',    'Api\EcommerceIntegrationsController::show/$1');
        $routes->post('(:segment)',   'Api\EcommerceIntegrationsController::connect/$1');
        $routes->delete('(:segment)', 'Api\EcommerceIntegrationsController::disconnect/$1');
    });

    // --- AI assistance ---
    $routes->post('ai/suggest', 'Api\AiController::suggest', ['filter' => 'auth']);
    $routes->post('ai/script',  'Api\AiController::script',  ['filter' => 'auth']);
    $routes->get('ai/usage',    'Api\AiController::usage',   ['filter' => 'auth']);
    $routes->get('ai/config',    'Api\AiController::getConfig',    ['filter' => ['auth', 'role:owner,admin']]);
    $routes->post('ai/config',   'Api\AiController::saveConfig',   ['filter' => ['auth', 'role:owner,admin']]);
    $routes->delete('ai/config', 'Api\AiController::deleteConfig', ['filter' => ['auth', 'role:owner,admin']]);

    // Reply-alert preferences + browser-push subscription
    $routes->get('notifications/settings',       'Api\NotificationsController::getSettings',  ['filter' => 'auth']);
    $routes->post('notifications/settings',      'Api\NotificationsController::saveSettings', ['filter' => ['auth', 'role:owner,admin']]);
    $routes->post('notifications/push/subscribe', 'Api\NotificationsController::subscribePush', ['filter' => 'auth']);
    $routes->get('notifications/feed',           'Api\NotificationsController::feed',         ['filter' => 'auth']);
    $routes->post('notifications/(:num)/read',   'Api\NotificationsController::markRead/$1',  ['filter' => 'auth']);
    $routes->post('notifications/read-all',      'Api\NotificationsController::markAllRead',  ['filter' => 'auth']);

    // --- Outbound webhooks / Zapier (owner + admin) ---
    $routes->group('webhooks', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',           'Api\WebhooksController::index');
        $routes->post('',          'Api\WebhooksController::create');
        $routes->put('(:num)',     'Api\WebhooksController::update/$1');
        $routes->delete('(:num)',  'Api\WebhooksController::delete/$1');
        $routes->post('(:num)/test', 'Api\WebhooksController::test/$1');
    });

    // --- Products / catalog ---
    $routes->group('products', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',           'Api\ProductsController::index');
        $routes->post('',          'Api\ProductsController::create');
        $routes->get('catalog',    'Api\ProductsController::getCatalog');
        $routes->post('catalog',   'Api\ProductsController::saveCatalog');
        $routes->put('(:num)',     'Api\ProductsController::update/$1');
        $routes->delete('(:num)',  'Api\ProductsController::delete/$1');
        $routes->post('(:num)/send', 'Api\ProductsController::send/$1');
    });

    // --- Inbox routing rules (owner + admin) ---
    $routes->group('routing-rules', ['filter' => ['auth', 'role:owner,admin']], static function (RouteCollection $routes): void {
        $routes->get('',          'Api\RoutingRulesController::index');
        $routes->post('',         'Api\RoutingRulesController::create');
        $routes->put('(:num)',    'Api\RoutingRulesController::update/$1');
        $routes->delete('(:num)', 'Api\RoutingRulesController::delete/$1');
    });

    // --- Shared inbox (all protected) ---
    $routes->group('inbox', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('',              'Api\InboxController::index');
        $routes->get('(:num)',        'Api\InboxController::show/$1');
        $routes->get('(:num)/messages',  'Api\InboxController::messages/$1');
        $routes->post('(:num)/messages', 'Api\InboxController::sendMessage/$1');
        $routes->patch('(:num)/assign',  'Api\InboxController::assign/$1');
        $routes->patch('(:num)/resolve', 'Api\InboxController::resolve/$1');
        $routes->patch('(:num)/reopen',  'Api\InboxController::reopen/$1');
        $routes->patch('(:num)/mark-read',    'Api\InboxController::markRead/$1');
        $routes->post('(:num)/upload-media',  'Api\InboxController::uploadMedia/$1');
        $routes->get('(:num)/stream',         'Api\InboxController::stream/$1');
    });
});

// ------------------------------------------------------------------
// Public routes — no auth (CORS applied for browser-facing endpoints)
// ------------------------------------------------------------------
$routes->group('', ['filter' => 'cors'], static function (RouteCollection $routes): void {
    // Hosted web form
    $routes->get( 'forms/(:alphanum)',         'Public\FormController::show/$1');
    $routes->post('forms/(:alphanum)/submit',  'Public\FormController::submit/$1');

    // Embed JS snippet  (/embed/{token}.js)
    $routes->get('embed/(:alphanum).js', 'Public\EmbedController::script/$1');
});

// ------------------------------------------------------------------
// Media proxy — token validated inside the controller (Bearer via ?token= param)
// No CORS filter needed; same-origin requests from the SPA only.
// ------------------------------------------------------------------
$routes->get('api/v1/inbox/media/(:any)', 'Api\InboxController::proxyMedia/$1');

// ------------------------------------------------------------------
// Facebook Login callback — public, because a browser redirect back from
// Meta carries no Authorization header; the single-use `state` is what
// authenticates it. Registered OUTSIDE the api/v1 group (like the media
// proxy above) to skip that group's filters, but kept under the /api/
// prefix on purpose: that is the only prefix both the production nginx
// and the Vite dev proxy already hand to PHP, so the flow needs no new
// server config anywhere. Must match the Meta app's redirect URI exactly.
// ------------------------------------------------------------------
$routes->get('api/v1/social/oauth/callback', 'Public\SocialOauthCallbackController::index');

// ------------------------------------------------------------------
// Uploaded social creatives — PUBLIC by necessity: Graph fetches the photo
// by URL with no session, so this cannot sit behind the auth filter. Outside
// the api/v1 group to skip that group's filters, but under /api/ because
// that is a prefix production nginx already routes to PHP. The filename is
// 32 random hex chars and is validated before any file is touched.
// ------------------------------------------------------------------
$routes->get('api/v1/social/media/(:num)/(:segment)', 'Public\SocialMediaController::show/$1/$2');

// ------------------------------------------------------------------
// Meta webhook endpoints — public, NO CORS filter (Meta doesn't need it)
// Signature verification is done inside the controller.
// ------------------------------------------------------------------
// --- Public "join the call" redirect ---
// Unauthenticated by necessity: the person clicking is a prospect, not a user.
// The bare /meet form is what the approved reminder template's URL button points
// at, since a static button cannot carry a per-meeting value.
$routes->get('meet',            'Public\MeetRedirectController::go');
$routes->get('meet/(:num)',     'Public\MeetRedirectController::go/$1');

$routes->get( 'webhooks/whatsapp',   'Webhooks\WhatsAppWebhookController::verify');
$routes->post('webhooks/whatsapp',   'Webhooks\WhatsAppWebhookController::receive');

// ── Alias: EaseMySale legacy path — Meta app webhook is registered to this path.
// Same controller handles both paths transparently.
$routes->get( 'api/v1/whatsapp/webhook', 'Webhooks\WhatsAppWebhookController::verify');
$routes->post('api/v1/whatsapp/webhook', 'Webhooks\WhatsAppWebhookController::receive');
$routes->get( 'webhooks/meta-leads', 'Webhooks\MetaLeadWebhookController::verify');
$routes->post('webhooks/meta-leads', 'Webhooks\MetaLeadWebhookController::receive');

// Google Ads Lead Forms — verified by the per-tenant google_key in the body.
$routes->post('webhooks/google-leads', 'Webhooks\GoogleLeadWebhookController::receive');
// Razorpay webhook — always registered; controller gates internally via FeatureGate
// Returning 200 prevents Razorpay from retrying indefinitely on wrong-mode installations.
$routes->post('webhooks/razorpay',   'Webhooks\RazorpayWebhookController::receive');
// Razorpay PAYMENT LINK webhook — per-tenant signature verified inside the controller.
$routes->post('webhooks/razorpay-payments', 'Webhooks\RazorpayPaymentsWebhookController::receive');
// E-commerce webhooks — per-tenant HMAC verified inside each controller.
$routes->post('webhooks/shopify',     'Webhooks\ShopifyWebhookController::receive');
$routes->post('webhooks/woocommerce', 'Webhooks\WooCommerceWebhookController::receive');
// Email marketing tracking — public, token in the path. Kept under /webhooks/
// and /forms/ because production nginx only routes those prefixes to PHP.
$routes->get('webhooks/email/open/(:segment)',  'Public\EmailTrackingController::open/$1');
$routes->get('webhooks/email/click/(:segment)', 'Public\EmailTrackingController::click/$1');
$routes->get('forms/unsubscribe/(:segment)',    'Public\EmailTrackingController::unsubscribeForm/$1');
$routes->post('forms/unsubscribe/(:segment)',   'Public\EmailTrackingController::unsubscribe/$1');

// Inbound email — tenant resolved from the per-tenant token in the path.
$routes->post('webhooks/inbound-email/(:segment)', 'Webhooks\InboundEmailWebhookController::receive/$1');
