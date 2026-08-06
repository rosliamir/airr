<?php

namespace App\Support;

/**
 * RBAC permission registry (M1.2). Plain class with string constants so values
 * are JSON-serializable and stable. Add new permissions here AND to all().
 */
class Permission
{
    // Reports
    const REPORTS_VIEW   = 'reports.view';
    const REPORTS_CREATE = 'reports.create';
    const REPORTS_EDIT   = 'reports.edit';
    const REPORTS_DELETE = 'reports.delete';
    const REPORTS_RUN    = 'reports.run';
    const REPORTS_EXPORT = 'reports.export';
    const REPORTS_PUBLISH = 'reports.publish';

    // Data sources (M2)
    const DATASOURCES_VIEW   = 'datasources.view';
    const DATASOURCES_MANAGE = 'datasources.manage';

    // Knowledge base / RAG (M3)
    const KB_VIEW   = 'kb.view';
    const KB_MANAGE = 'kb.manage';

    // API keys (M11)
    const APIKEYS_MANAGE = 'apikeys.manage';

    // Governance / admin (M14)
    const USERS_MANAGE = 'users.manage';
    const ROLES_MANAGE = 'roles.manage';
    const AUDIT_READ   = 'audit.read';
    const SETTINGS_MANAGE = 'settings.manage';
    const PROJECTS_VIEW   = 'projects.view';
    const PROJECTS_MANAGE = 'projects.manage';

    // Menu administration (M14 — DB-driven navigation)
    const MENUS_MANAGE = 'menus.manage';

    // Dashboards (widget-based authoring)
    const DASHBOARDS_VIEW   = 'dashboards.view';
    const DASHBOARDS_CREATE = 'dashboards.create';
    const DASHBOARDS_EDIT   = 'dashboards.edit';
    const DASHBOARDS_DELETE = 'dashboards.delete';
    const DASHBOARDS_RUN    = 'dashboards.run';

    // Tasks (dashboard widget data source)
    const TASKS_VIEW   = 'tasks.view';
    const TASKS_MANAGE = 'tasks.manage';

    // Announcements (dashboard widget data source)
    const ANNOUNCEMENTS_VIEW   = 'announcements.view';
    const ANNOUNCEMENTS_MANAGE = 'announcements.manage';

    public static function all(): array
    {
        return [
            self::REPORTS_VIEW, self::REPORTS_CREATE, self::REPORTS_EDIT, self::REPORTS_DELETE,
            self::REPORTS_RUN, self::REPORTS_EXPORT, self::REPORTS_PUBLISH,
            self::DATASOURCES_VIEW, self::DATASOURCES_MANAGE,
            self::KB_VIEW, self::KB_MANAGE,
            self::APIKEYS_MANAGE,
            self::USERS_MANAGE, self::ROLES_MANAGE, self::AUDIT_READ, self::SETTINGS_MANAGE,
            self::PROJECTS_VIEW, self::PROJECTS_MANAGE,
            self::MENUS_MANAGE,
            self::DASHBOARDS_VIEW, self::DASHBOARDS_CREATE, self::DASHBOARDS_EDIT, self::DASHBOARDS_DELETE, self::DASHBOARDS_RUN,
            self::TASKS_VIEW, self::TASKS_MANAGE,
            self::ANNOUNCEMENTS_VIEW, self::ANNOUNCEMENTS_MANAGE,
        ];
    }
}
