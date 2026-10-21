<?php
namespace WPFlatFinance;

defined( 'ABSPATH' ) || exit;

/** WPFlat Finance's tables. */
class Install {

	const DB_VERSION = '1';
	const OPTION     = 'wpflatfinance_db_version';

	/** Cheap check on every request so an updated plugin upgrades itself. */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install_tables();
		}
	}

	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = array();
		foreach ( Schema::OWN as $n ) {
			$t[ $n ] = Schema::table( $n );
		}
		$sql = array();

		$sql[] = "CREATE TABLE {$t['catalog']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  description text,
  default_billing_period varchar(40) NOT NULL DEFAULT '',
  default_cost decimal(14,2) NOT NULL DEFAULT 0,
  default_price decimal(14,2) NOT NULL DEFAULT 0,
  default_billing_treatment varchar(40) NOT NULL DEFAULT '',
  default_currency char(3) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'recurring',
  type varchar(10) NOT NULL DEFAULT 'service',
  pricing varchar(10) NOT NULL DEFAULT 'fixed',
  unit_label varchar(40) NOT NULL DEFAULT '',
  subject_label varchar(60) NOT NULL DEFAULT '',
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY type (type)
) $charset;";

		$sql[] = "CREATE TABLE {$t['client_services']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(190) NOT NULL DEFAULT '',
  subject varchar(190) NOT NULL DEFAULT '',
  start_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  cost decimal(14,2) NOT NULL DEFAULT 0,
  client_price decimal(14,2) NOT NULL DEFAULT 0,
  quantity decimal(10,2) NOT NULL DEFAULT 1,
  unit_price decimal(14,2) NOT NULL DEFAULT 0,
  unit_cost decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  billing_treatment varchar(40) NOT NULL DEFAULT '',
  billing_period varchar(40) NOT NULL DEFAULT '',
  interval_days int(11) NOT NULL DEFAULT 0,
  next_renewal_date date DEFAULT NULL,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY service_id (service_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['renewals']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  due_date date DEFAULT NULL,
  frequency varchar(40) NOT NULL DEFAULT '',
  interval_days int(11) NOT NULL DEFAULT 0,
  cost decimal(14,2) NOT NULL DEFAULT 0,
  price decimal(14,2) NOT NULL DEFAULT 0,
  quantity decimal(10,2) NOT NULL DEFAULT 1,
  unit_price decimal(14,2) NOT NULL DEFAULT 0,
  subject varchar(190) NOT NULL DEFAULT '',
  currency char(3) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'upcoming',
  renewed_on date DEFAULT NULL,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY client_service_id (client_service_id),
  KEY status_due (status,due_date)
) $charset;";

		$sql[] = "CREATE TABLE {$t['charges']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  renewal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  description varchar(255) NOT NULL DEFAULT '',
  cost decimal(14,2) NOT NULL DEFAULT 0,
  price decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  incurred_on date DEFAULT NULL,
  billing_timing varchar(40) NOT NULL DEFAULT 'now',
  bill_on_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY status_bill (status,bill_on_date)
) $charset;";

		$sql[] = "CREATE TABLE {$t['invoices']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  invoice_number varchar(60) NOT NULL DEFAULT '',
  title varchar(190) NOT NULL DEFAULT '',
  description text,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  issue_date date DEFAULT NULL,
  due_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  invoice_url varchar(500) NOT NULL DEFAULT '',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount_paid decimal(14,2) NOT NULL DEFAULT 0,
  subtotal decimal(14,2) NOT NULL DEFAULT 0,
  discount_type varchar(10) NOT NULL DEFAULT 'none',
  discount_value decimal(14,2) NOT NULL DEFAULT 0,
  discount_amount decimal(14,2) NOT NULL DEFAULT 0,
  tax_rate decimal(6,2) NOT NULL DEFAULT 0,
  tax_amount decimal(14,2) NOT NULL DEFAULT 0,
  terms text,
  public_token varchar(64) NOT NULL DEFAULT '',
  sent_at datetime DEFAULT NULL,
  viewed_at datetime DEFAULT NULL,
  view_count int(11) NOT NULL DEFAULT 0,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY status_due (status,due_date),
  KEY invoice_number (invoice_number),
  KEY public_token (public_token)
) $charset;";

		$sql[] = "CREATE TABLE {$t['invoice_items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_type varchar(20) NOT NULL DEFAULT 'manual',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  description varchar(255) NOT NULL DEFAULT '',
  period_start date DEFAULT NULL,
  period_end date DEFAULT NULL,
  kind varchar(10) NOT NULL DEFAULT 'fixed',
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cost decimal(14,2) NOT NULL DEFAULT 0,
  quantity decimal(10,2) NOT NULL DEFAULT 1,
  unit_price decimal(14,2) NOT NULL DEFAULT 0,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id),
  KEY client_service_id (client_service_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['invoice_installments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  label varchar(190) NOT NULL DEFAULT '',
  due_date date DEFAULT NULL,
  percent decimal(6,2) DEFAULT NULL,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['payments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  paid_on date DEFAULT NULL,
  reference varchar(190) NOT NULL DEFAULT '',
  method varchar(40) NOT NULL DEFAULT '',
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id),
  KEY client_id (client_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['email_log']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT 'invoice',
  to_emails text,
  cc_emails text,
  bcc_emails text,
  subject varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'sent',
  error text,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id),
  KEY client_id (client_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['expenses']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  spent_on date DEFAULT NULL,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  category varchar(40) NOT NULL DEFAULT '',
  vendor varchar(190) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  method varchar(60) NOT NULL DEFAULT '',
  reference varchar(120) NOT NULL DEFAULT '',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  recurring_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'paid',
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY spent_on (spent_on),
  KEY category (category),
  KEY department_id (department_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['recurring_expenses']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  vendor varchar(190) NOT NULL DEFAULT '',
  category varchar(40) NOT NULL DEFAULT '',
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  period varchar(40) NOT NULL DEFAULT 'monthly',
  next_date date DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'active',
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY next_date (next_date)
) $charset;";

		$sql[] = "CREATE TABLE {$t['pay_allocations']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(10) NOT NULL DEFAULT 'phase',
  scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
  team_member_id bigint(20) unsigned NOT NULL DEFAULT 0,
  mode varchar(10) NOT NULL DEFAULT 'fixed',
  value decimal(14,2) NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY scope (scope,scope_id),
  KEY team_member_id (team_member_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['pay_items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  team_member_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT 'bonus',
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  phase_id bigint(20) unsigned NOT NULL DEFAULT 0,
  task_id bigint(20) unsigned NOT NULL DEFAULT 0,
  allocation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  period varchar(7) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  earned_on date DEFAULT NULL,
  status varchar(12) NOT NULL DEFAULT 'payable',
  payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY team_member_id (team_member_id),
  KEY status (status),
  KEY phase_id (phase_id),
  KEY payout_id (payout_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['payouts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  number varchar(30) NOT NULL DEFAULT '',
  team_member_id bigint(20) unsigned NOT NULL DEFAULT 0,
  paid_on date DEFAULT NULL,
  method varchar(60) NOT NULL DEFAULT '',
  reference varchar(120) NOT NULL DEFAULT '',
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  notes text,
  status varchar(12) NOT NULL DEFAULT 'paid',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY team_member_id (team_member_id),
  KEY paid_on (paid_on)
) $charset;";

		// What WPFlat Core's and WPFlat Tasks' records carry for money, kept here so those plugins stay money-free.
		$sql[] = "CREATE TABLE {$t['client_billing']} (
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  retainer_amount decimal(14,2) NOT NULL DEFAULT 0,
  retainer_period varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (client_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['team_pay']} (
  team_member_id bigint(20) unsigned NOT NULL DEFAULT 0,
  salary_amount decimal(14,2) NOT NULL DEFAULT 0,
  salary_period varchar(20) NOT NULL DEFAULT 'monthly',
  currency char(3) NOT NULL DEFAULT '',
  bank_name varchar(120) NOT NULL DEFAULT '',
  account_name varchar(190) NOT NULL DEFAULT '',
  account_number varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY  (team_member_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['project_money']} (
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  price decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  PRIMARY KEY  (project_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['phase_money']} (
  phase_id bigint(20) unsigned NOT NULL DEFAULT 0,
  price decimal(14,2) NOT NULL DEFAULT 0,
  team_budget decimal(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (phase_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['task_money']} (
  task_id bigint(20) unsigned NOT NULL DEFAULT 0,
  pay_amount decimal(14,2) NOT NULL DEFAULT 0,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (task_id),
  KEY invoice_id (invoice_id)
) $charset;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}
		update_option( self::OPTION, self::DB_VERSION, false );
	}
}
