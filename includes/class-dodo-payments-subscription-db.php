<?php

/**
 * Database operations for Dodo Payments Subscription mappings
 *
 * @since 0.3.0
 */
class Dodo_Payments_Subscription_DB
{
  private static string $table_name = 'dodo_payments_subscription_mappings';
  private static ?bool $table_available = null;
  private const SCHEMA_VERSION = '1.0.0';
  private const SCHEMA_OPTION = 'dodo_payments_subscription_db_version';

  private static function get_table_name()
  {
    global $wpdb;

    return $wpdb->prefix . self::$table_name;
  }

  /**
   * Creates the database table for mapping WooCommerce subscription IDs to Dodo Payments subscription IDs.
   *
   * The table includes unique constraints on both subscription ID columns and a timestamp for record creation.
   *
   * @return void
   */
  public static function create_table()
  {
    global $wpdb;

    $table_name = self::get_table_name();

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wc_subscription_id bigint(20) NOT NULL,
            dodo_subscription_id varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY wc_subscription_id (wc_subscription_id),
            UNIQUE KEY dodo_subscription_id (dodo_subscription_id)
        ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    self::$table_available = self::table_exists();
  }

  private static function table_exists()
  {
    global $wpdb;

    $table_name = self::get_table_name();

    return $wpdb->get_var(
      $wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
    ) === $table_name;
  }

  public static function maybe_create_table()
  {
    if (null !== self::$table_available) {
      return self::$table_available;
    }

    $needs_install = !self::table_exists() || get_option(self::SCHEMA_OPTION) !== self::SCHEMA_VERSION;

    if ($needs_install) {
      self::create_table();
    } else {
      self::$table_available = true;
    }

    return true === self::$table_available;
  }

  /**
   * Inserts or updates the mapping between a WooCommerce subscription ID and a Dodo Payments subscription ID.
   *
   * If a mapping for the given WooCommerce subscription ID already exists, it is updated; otherwise, a new mapping is created.
   *
   * @param int $wc_subscription_id The WooCommerce subscription ID to map.
   * @param string $dodo_subscription_id The corresponding Dodo Payments subscription ID.
   */
  public static function save_mapping($wc_subscription_id, $dodo_subscription_id)
  {
    global $wpdb;

    if (!self::maybe_create_table()) {
      return false;
    }

    $table_name = self::get_table_name();

    $wpdb->replace(
      $table_name,
      array(
        'wc_subscription_id' => $wc_subscription_id,
        'dodo_subscription_id' => $dodo_subscription_id,
      ),
      array(
        '%d',
        '%s',
      )
    );
  }

  /**
   * Retrieves the Dodo Payments subscription ID associated with a given WooCommerce subscription ID.
   *
   * @param int $wc_subscription_id The WooCommerce subscription ID to look up.
   * @return string|null The corresponding Dodo Payments subscription ID, or null if no mapping exists.
   */
  public static function get_dodo_subscription_id($wc_subscription_id)
  {
    global $wpdb;

    if (!self::maybe_create_table()) {
      return null;
    }

    $table_name = self::get_table_name();

    $result = $wpdb->get_var($wpdb->prepare(
      "SELECT dodo_subscription_id FROM $table_name WHERE wc_subscription_id = %d",
      $wc_subscription_id
    ));

    return $result;
  }

  /**
   * Retrieves the WooCommerce subscription ID associated with a given Dodo Payments subscription ID.
   *
   * @param string $dodo_subscription_id The Dodo Payments subscription ID to look up.
   * @return int|null The corresponding WooCommerce subscription ID, or null if no mapping exists.
   */
  public static function get_wc_subscription_id($dodo_subscription_id)
  {
    global $wpdb;

    if (!self::maybe_create_table()) {
      return null;
    }

    $table_name = self::get_table_name();

    $result = $wpdb->get_var($wpdb->prepare(
      "SELECT wc_subscription_id FROM $table_name WHERE dodo_subscription_id = %s",
      $dodo_subscription_id
    ));

    return $result ? (int) $result : null;
  }

  /****
   * Removes the mapping entry for the specified WooCommerce subscription ID from the database.
   *
   * @param int $wc_subscription_id The WooCommerce subscription ID whose mapping should be deleted.
   */
  public static function delete_mapping($wc_subscription_id)
  {
    global $wpdb;

    if (!self::maybe_create_table()) {
      return false;
    }

    $table_name = self::get_table_name();

    $wpdb->delete(
      $table_name,
      array('wc_subscription_id' => $wc_subscription_id),
      array('%d')
    );
  }
}