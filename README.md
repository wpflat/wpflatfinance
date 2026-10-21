# WPFlat Finance

Catalog, subscriptions and renewals, invoices, payments, expenses, payroll and profit for the WPFlat suite, with public invoice and payslip pages.

Needs WPFlat Core.

Part of the **WPFlat** suite (WPFlat Core, WPFlat Tasks, WPFlat Finance, WPFlat Sign, WPFlat Files). Each plugin has its own repository and installs on its own; the modules need WPFlat Core.

## Install

Download or clone this repository, put the `wpflatfinance` folder in `wp-content/plugins/`, and activate it (activate WPFlat Core first — this plugin refuses to start without it). The built scripts in `build/` are committed, so nothing needs compiling to use it.

## Develop

```
npm install
npm run build      # rebuilds build/ from ui/
```

PHP lives in `includes/`, the Vue source in `ui/`. The shared test suites and deploy tools live in the WPFlat workspace (`wpflat/` next to this folder).

License: GPL-2.0-or-later.
