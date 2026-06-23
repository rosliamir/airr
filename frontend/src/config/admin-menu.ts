// AIRR Studio (Build side) main menu — grouped by workflow.
// Items auto-hide based on RBAC permission + edition feature flags.

export type MenuItem = {
  label: string
  route: string // route name
  icon: string // lucide icon name
  module: string // M1..M15
  permission?: string // required RBAC permission (any-of via array later)
  feature?: string // required edition feature flag
  phase: number // roadmap phase it lands in
  ready: boolean // true = built, false = placeholder "coming soon"
}

export type MenuGroup = {
  title: string | null // null = ungrouped (top)
  items: MenuItem[]
}

export const adminMenu: MenuGroup[] = [
  {
    title: null,
    items: [
      { label: 'Dashboard', route: 'dashboard', icon: 'LayoutDashboard', module: 'M1', phase: 0, ready: true },
    ],
  },
  {
    title: 'Author',
    items: [
      { label: 'Reports', route: 'reports', icon: 'FileText', module: 'M6', permission: 'reports.view', phase: 1, ready: false },
      { label: 'Templates', route: 'templates', icon: 'LayoutTemplate', module: 'M6', permission: 'reports.create', phase: 1, ready: false },
    ],
  },
  {
    title: 'Data & Intelligence',
    items: [
      { label: 'Data Sources', route: 'datasources', icon: 'Database', module: 'M2', permission: 'datasources.view', phase: 1, ready: false },
      { label: 'Knowledge Base', route: 'knowledge-base', icon: 'BookOpen', module: 'M3', permission: 'kb.view', phase: 2, ready: false },
      { label: 'AI Orchestration', route: 'ai-orchestration', icon: 'Bot', module: 'M4', permission: 'reports.create', feature: 'multi_agent', phase: 3, ready: false },
    ],
  },
  {
    title: 'Delivery',
    items: [
      { label: 'Compile & Publish', route: 'compile', icon: 'Package', module: 'M7', permission: 'reports.publish', feature: 'cra_compile', phase: 4, ready: false },
      { label: 'API Delivery', route: 'api-delivery', icon: 'Webhook', module: 'M11', permission: 'apikeys.manage', phase: 4, ready: false },
      { label: 'Scheduler', route: 'scheduler', icon: 'Clock', module: 'M12', permission: 'reports.run', phase: 5, ready: false },
    ],
  },
  {
    title: 'Governance',
    items: [
      { label: 'Projects', route: 'projects', icon: 'FolderKanban', module: 'M14', permission: 'projects.view', phase: 0, ready: true },
      { label: 'Users & Roles', route: 'users', icon: 'Users', module: 'M14', permission: 'users.manage', feature: 'rbac_full', phase: 0, ready: true },
      { label: 'Audit Log', route: 'audit', icon: 'ScrollText', module: 'M1', permission: 'audit.read', phase: 0, ready: true },
      { label: 'Settings', route: 'settings', icon: 'Settings', module: 'M14', permission: 'settings.manage', phase: 0, ready: true },
    ],
  },
]
