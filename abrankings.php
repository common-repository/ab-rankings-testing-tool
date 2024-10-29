<?php
/*
   Plugin Name: SEO Scout
   Plugin URI: https://seoscout.com
   description: Run SEO Tests, optimize your content using our SEO editor, track and research keywords and rankings
   Version: 0.9.83
   Author: SEO Scout
   License: GPL2
   */
require_once "ABRankings.class.php";
require_once "metabox.inc";

// Initialization and Hooks
global $wpdb;
global $wp_version;
global $abr_version;
global $abr_db_version;
global $abr_tests_table_name;
global $abr_urls_table_name;
global $wp_version;

if ($_GET['abrankings']) {
    if($_GET['refresh_test']) {
        abr_update_tests(false);
        exit;
    }
    abr_debug_info();
    exit;
}

function abr_debug_info() {
    global $wpdb;
    global $abr_tests_table_name;
    global $abr_urls_table_name;

    $data=array(
        'id'=>get_option('abr_id'),
        'token_length'=> strlen(get_option('abr_token')),

    );

    $data['tests']=$wpdb->get_results("SELECT * FROM $abr_tests_table_name ");
    $data['urls']=$wpdb->get_results("SELECT * FROM $abr_urls_table_name ");

    echo json_encode($data);
}

/*
delete_option('abr_id');
delete_option('abr_token');*/

$abr_version       = '1.0.0';
$abr_db_version    = '0.0.1';
$abr_tests_table_name    = $wpdb->prefix.'abr_tests';
$abr_urls_table_name    = $wpdb->prefix.'abr_test_urls';


function abr_cron_schedules($schedules){
    if(!isset($schedules["5min"])){
        $schedules["5min"] = array(
            'interval' => 5*60,
            'display' => __('Once every 5 minutes'));
    }
    if(!isset($schedules["10min"])){
        $schedules["5min"] = array(
            'interval' => 10*60,
            'display' => __('Once every 10 minutes'));
    }
    if(!isset($schedules["15min"])){
        $schedules["15min"] = array(
            'interval' => 15*60,
            'display' => __('Once every 15 minutes'));
    }
    if(!isset($schedules["30min"])){
        $schedules["30min"] = array(
            'interval' => 30*60,
            'display' => __('Once every 30 minutes'));
    }
    return $schedules;
}
add_filter('cron_schedules','abr_cron_schedules');
if ( ! wp_next_scheduled( 'abr_update_tests' ) ) {
    wp_schedule_event( time(), '5min', 'abr_update_tests' );
}

add_action( 'abr_update_tests', 'abr_update_tests' );
//$filepath=__FILE__;
register_activation_hook(__FILE__,'abr_install');

if ($wp_version >= '2.7') {

    register_uninstall_hook(__FILE__,'abr_uninstall');

} else {

    register_deactivation_hook(__FILE__,'abr_uninstall');

}

function abr_install() {


    global $wpdb;
    global $abr_tests_table_name;
    global $abr_urls_table_name;
    global $abr_db_version;

    // create table on first install
    // Move to drop tables to ensure we are not getting weird errors
//    if ($wpdb->get_var("show tables like '$abr_tests_table_name'") != $abr_tests_table_name) {
    $wpdb->query("DROP TABLE IF EXISTS `$abr_tests_table_name`;");
    $sql=" CREATE TABLE `$abr_tests_table_name` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `alters` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `started` date DEFAULT NULL,
  `ended` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ";
    $x= $wpdb->query($sql);
    echo $wpdb->print_error();

    $wpdb->query("DROP TABLE IF EXISTS `$abr_urls_table_name`");
    $urls= "CREATE TABLE `$abr_urls_table_name` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` text NOT NULL,
  `test_id` int(11) NOT NULL,
  `control` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `test_urls_test_id_index` (`test_id`)
) ENGINE=InnoDB AUTO_INCREMENT=339 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $x=$wpdb->query($urls);
    echo $wpdb->print_error();

//    }
    // If we have the abr_ settings stored, then the user is reactivating the plugin, so lets grab their tests:
    if ((get_option('abr_token','')!='') && (get_option('abr_id','')!='')) abr_update_tests(false);

}


function abr_uninstall() {

    global $wpdb;
    global $abr_tests_table_name;
    global $abr_urls_table_name;

    // delete table
    if($wpdb->get_var("show tables like '$abr_urls_table_name'") == $abr_urls_table_name) {

        $table_name = $abr_urls_table_name;
        $sql = "DROP TABLE IF EXISTS $table_name";
        $wpdb->query($sql);
        $table_name = $abr_tests_table_name;
        $sql = "DROP TABLE IF EXISTS $table_name";
        $wpdb->query($sql);
    }

}

function abr_update_tests($exit=TRUE) {
    global $wpdb;
    global $abr_tests_table_name;
    global $abr_urls_table_name;
    $ab = new ABRankings();
    $token='';
    $flush_cache=FALSE;
    $last_update=new DateTimeImmutable( get_option('abr_last_updated','1970-01-01') );

    if (get_option('abr_token','')=='') {
        $_SESSION['abr_message']='Please update your API token in order to download tests';
        add_action('admin_notices', 'abr_admin_notice__error');
//        abr_admin_notice__error();
    }elseif (get_option('abr_id','')=='') {
        $_SESSION['abr_message']='Please select the site you wish to choose tests for and save changes';
        add_action('admin_notices', 'abr_admin_notice__error');
//        abr_admin_notice__error();
    }
    $tests = $ab->getTests(get_option('abr_id',''), get_option('abr_token',''));
    $http_response_header=$GLOBALS['http_response'];
    if (!$exit) {
        // Todo: find a way to pass these messages back to settings page on save changes filter
        if ($GLOBALS['http_response']!=200) {
            if ($GLOBALS['http_response']==403) {
                $_SESSION['abr_message'] = "Received an access denied error downloading tests. <br>Are you sure your API token is correct?";
                add_action('admin_notices', 'abr_admin_notice__error');
//                abr_admin_notice__error();
            } else {
                $_SESSION['abr_message'] = "Received an error downloading tests: <br>" . $GLOBALS['http_response'] . '<br>Are you sure your API token is correct?';
                add_action('admin_notices', 'abr_admin_notice__error');
//                abr_admin_notice__error();
            }

        } elseif (count($tests) == 0) {
            $_SESSION['abr_message'] = "Successfully connected but no tests were found";
            add_action('admin_notices', 'abr_admin_notice__error');
//            abr_admin_notice__error();
            return;
        } else {
            $_SESSION['abr_message'] = "Successfully connected and downloaded " . count($tests) . " tests";
            add_action('admin_notices', 'abr_admin_notice__updated');
//            abr_admin_notice__updated();
        }
    }
    update_option('abr_last_updated',date('Y-m-d H:s:i',time()),true);
    $wpdb->query("TRUNCATE TABLE $abr_tests_table_name;");
    $wpdb->query("TRUNCATE TABLE $abr_urls_table_name;");
    if(is_array($tests)) {
        foreach ($tests as $test) {
            $data = array(
                'id' => $test['id'],
                'name' => $test['name'],
                'alters' => json_encode($test['alters']),


            );
            if ($test['started']) {
                $data['started'] = $test['started'];
                if (new DateTimeImmutable($data['started']) > $last_update) $flush_cache = TRUE;
            }
            if ($test['ended']) $data['ended'] = $test['ended'];
            $wpdb->replace(
                $abr_tests_table_name,
                $data
            );
            foreach ($test['pages'] as $page) {
                $data = array(
                    'id' => $page['id'],
                    'url' => $page['page']['page'],
                    'control' => $page['control'],
                    'test_id' => $page['test_id']
            );
                $wpdb->replace(
                    $abr_urls_table_name,
                    $data
                );
            }
        }
    }
//    $wpdb->replace($abr_tests_table_name,['id'=>-1,'name'=>'LAST UPDATED','alters'=>date('Y-m-d H:s:i')]);

    if ($flush_cache) abr_clear_caches();


//    if($exit) exit;
}
//abr_clear_caches();
function abr_clear_caches() {
    // WP Fastest Cache
    if(isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')){
        $GLOBALS['wp_fastest_cache']->deleteCache();
    }
    // Lightspeed cache
    if (method_exists('LiteSpeed_Cache_API','purge_all')) LiteSpeed_Cache_API::purge_all();
    // Comet cache
    if (method_exists('comet_cache','clear')) comet_cache::clear();
    // HyperCache
    if (method_exists('HyperCache','clean')) HyperCache::clean();
    // WP Super Cache
    if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
    // W3TC
    if (function_exists( 'w3tc_flush_all' )) w3tc_flush_all();
    else {
        if (file_exists(ABSPATH . PLUGINDIR . 'w3-total-cache/w3-total-cache-api.php')) {
            require_once ABSPATH . PLUGINDIR . 'w3-total-cache/w3-total-cache-api.php';
            if (function_exists( 'w3tc_flush_all' )) w3tc_flush_all();
        }
    }
    // KeyCdN cache enabler
    if ( has_action('ce_clear_cache') ) {
        do_action('ce_clear_cache');
    }




}

function abr_buffer_callback($buffer) {
    // modify buffer here, and then return the updated code
    $GLOBALS['abr_buffer'].=$buffer;
    return '';
}

function abr_buffer_start() {

}
$GLOBALS['abr_buffer']='';
function abr_buffer_end() {
    global $wpdb;
    global $abr_tests_table_name;
    global $abr_urls_table_name;
    $content=ob_get_contents();
    // $content=abr_buffer_callback($content);
    @ob_end_clean();
    $buffer=$GLOBALS['abr_buffer'].$content;
//    print_r($buffer);
    if (trim($buffer)!='') {
        $ab = new ABRankings();
        // lookup test - local db or direct api call? Let's go direct for now, fix later

        $url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        $sql="SELECT * FROM $abr_urls_table_name u, $abr_tests_table_name t 
        WHERE u.url = '%s' AND u.test_id=t.id AND t.ended IS NULL";
        $test=$wpdb->get_row( $wpdb->prepare($sql, $url), 'ARRAY_A');
//        print_r($test);
        if (isset($test)) {
            // unpack our serialized strings..
            $alters=json_decode($test['alters'],true);
//            print_r($alters);
            $test['alters'] = $alters['alters'];
            $test['grabbers'] = $alters['grabbers'];
            // get any existing tokens..
            if ($id = get_the_ID()) {
                $tokens = get_post_meta($id);
//            print_r($tokens);
            } else $tokens = array();

            if ((!isset($test['ended'])) && ($test['control'] != 1)) $buffer = $ab->alterHTML($buffer, $test, $tokens);
        }
    }
    echo $buffer;
}
ob_start('abr_buffer_callback');

abr_buffer_start();
add_action('shutdown', 'abr_buffer_end');
remove_action('shutdown','wp_ob_end_flush_all');

add_action('admin_menu', function() {
add_menu_page('SEO Scout Settings', 'SEO Scout', 'manage_options', 'abrankings', 'abrankings_settings', 'dashicons-chart-line' );
// if their site is setup, lets create some submenus for rank checking etc
if (!empty(get_option('abr_id',''))) {
    add_submenu_page('abrankings','Settings','Settings', 'manage_options','abrankings','abrankings_settings');

    add_submenu_page('abrankings','Keyword Research','Keyword Research', 'manage_options','abrankings-keywords','abrankings_keywords');
    add_submenu_page('abrankings','Rank Checker','Rank Checker', 'manage_options','abrankings-rankchecker','abrankings_rankchecker');
}
wp_enqueue_style('seoscout', plugins_url('/ab-rankings-testing-tool/abrankings.css',dirname(__FILE__ )));
add_options_page( 'AB Rankings Settings', 'A/B Rankings', 'manage_options', 'abrankings', 'abrankings_settings' );
});

function abrankings_keywords() {
    wp_enqueue_script( 'seoscout',
//        'http://abranker.test/js/googleapps.js',
        'https://app.seoscout.com/js/googleapps.js',
        [],
        [],
        true);

    ?>
    <div class="plugin-header">
      <img src="<?php echo plugin_dir_url( __FILE__ ) . 'images/seoscout-logo.png'; ?>" width="190">
      <h1>Keyword Research</h1>
    </div>
    <div id="app">
        <keyword-suggestions app-url="<?php echo get_site_url(); ?>"></keyword-suggestions>
    </div>
    <?php
}

function abrankings_rankchecker() {
    wp_enqueue_script( 'seoscout',
        'http://app.seoscout.com/js/googleapps.js',
//        'http://abranker.test/js/googleapps.js',
        [],
        [],
        true);
//    wp_enqueue_style('seoscout', 'https://abrankings.com/css/app.css');
    ?>
      <div class="plugin-header">
         <img src="<?php echo plugin_dir_url( __FILE__ ) . 'images/seoscout-logo.png'; ?>" width="190">
         <h1>Rank Tracking</h1>
      </div>
      <div id="app">
         <keyword-table token="<?php echo get_option('abr_token','')?>" site-id=<?php echo get_option('abr_id','')?> ></keyword-table>
      </div>
    <?php
}




add_action( 'admin_init', function() {
    // register options here, copy and paste the following line and to add options beside map_name (map: my awesome plugin <img draggable="false" class="emoji" alt="😉" src="https://s.w.org/images/core/emoji/2.4/svg/1f609.svg" scale="0"> )
    add_settings_section('abr_settings','',null,'abr_settings');
    if(get_option('abr_token',null)!=null) add_settings_field("abr_id","Choose your site","display_abr_id","abr_settings","abr_settings");
    add_settings_field("abr_token","Enter your AB/Rankings API Token","display_abr_token","abr_settings","abr_settings");
    register_setting( 'abr_settings', 'abr_id' );
    register_setting( 'abr_settings', 'abr_token' );
    register_setting( 'abr_settings', 'abr_last_updated' );
});

add_action( 'rest_api_init', function() {
   $abrankings = new ABRankings;
   $abrankings->add_routes();
});


add_filter('update_option_abr_token','abr_select_site',10);
add_filter('update_option_abr_id', 'abr_update_tests',10);

function abr_select_site()
{
    if ($token = get_option('abr_token', NULL)) {
        $ab = new ABRankings();
        $sites = $ab->getSites($token);
        if(is_array($sites)) {
            foreach ($sites as $site) {
                $url=parse_url($site['url'], PHP_URL_HOST);
                if ($url==$_SERVER['HTTP_HOST']) $id=$site['id'];
            }
        }
    }
//$id=1;
    if (isset($id)) {
//echo     "Login successful. We have downloaded tests for yi";
        update_option('abr_id',$id);
        abr_update_tests(false);
    } else {
        if (count($sites)>0 ) {
            $_SESSION['abr_message'] = "Connected successfully! Please select your site url from the dropdown box";
            add_action('admin_notices', 'abr_admin_notice__error');
        } else {
            $_SESSION['abr_message'] = "No sites found - are you sure your API token is correct and you have added your sites to <a href='https://app.seoscout.com/sites' target='_blank'>seoscout.com</a>";
            add_action('admin_notices', 'abr_admin_notice__error');

        }

    }

}
function display_abr_id() {
    if ($token=get_option('abr_token',NULL)) {
        $ab = new ABRankings();
        $sites = $ab->getSites($token);
        $style='';
        $id=get_option('abr_id','');
        if(empty($id)) $style=' style="background:#fee;padding:5px;display:block" ';
        echo "<label $style >";

        echo "<select  name=\"abr_id\" id=\"abr_id\"  style='width:25em' >";
//        if (get_option('abr_id','')=='')
        echo "<option value=\"\"  selected>Please choose your site</option>";
        if (is_array($sites)) {
            foreach ($sites as $site) {
                if ($site['id'] == get_option('abr_id', '')) $selected = 'selected';
                else $selected = '';
                echo "<option $selected value='" . $site['id'] . "'>" . $site['url'] . "</option>";
            }
        }
        echo "</select></label>";
    }
}
function display_abr_token() {
   $style='';
   $token = get_option('abr_token', '');
   if(empty($token)) $style=' style="background:#fee;padding:5px;display:block" ';
   ?>
   <label <?php echo $style ?>>
      <input type="text" name="abr_token" id="abr_token" maxlength="64" style='width:25em' value="<?php echo $token;?>"/>
      <br><small>You can create an API key <a href="https://apps.seoscout.com/settings#/api">here</a>.</small>
   </label>
   <br/>
   <?php
}

function abrankings_settings() {
      global $wpdb;
      global $abr_tests_table_name;
      global $abr_urls_table_name;

      $sql="SELECT t.*,count(u.id) as url FROM $abr_urls_table_name u, $abr_tests_table_name t WHERE u.test_id=t.id AND t.ended IS NULL group by t.id";
      $tests=$wpdb->get_results( $wpdb->prepare($sql, array()), 'ARRAY_A');


      if ($_GET['action']=='refresh') abr_update_tests(false);
      abr_admin_notice__error();
      settings_errors();
      wp_enqueue_script( 'seoscout',
//        'http://abranker.test/js/googleapps.js',
        'https://app.seoscout.com/js/googleapps.js',
        [],
        [],
        true);
      if ($token = get_option('abr_token', NULL)) {
         $ab = new ABRankings();
         $sites = $ab->getSites($token);
         global $selected;
         if (is_array($sites)) {
            foreach ($sites as $site) {
                if ($site['id'] == get_option('abr_id', '')) $selected = $site['id'];
            }
        }
      }

      $alloptions  = wp_load_alloptions();
      $lastUpdated = get_option('abr_last_updated', '');
   ?>

      <div class="wrap">
         <div id="app" class="app--settings">
            <wordpress-settings :sites='<?php echo json_encode($sites); ?>'
                                token="<?php echo $token; ?>"
                                settings-fields='<?php wp_nonce_field( "$option_group-options" ); ?>'
                                selected-domain="<?php echo $selected; ?>" :tests="<?php echo htmlspecialchars(json_encode($tests), ENT_QUOTES, 'UTF-8'); ?>" nonce="<?php echo wp_create_nonce( 'wp_rest' ); ?>"
                                last-updated="<?php echo $lastUpdated; ?>"></wordpress-settings>
         </div>
         <div class="welcome-panel">
            <form action="options.php" method="post" id="settingsForm">
               <?php
               settings_fields( 'abr_settings' );
               do_settings_sections( 'abr_settings' );
               submit_button();
               echo "<p>Tests are updated automatically, but if you are having issues you can force an update below:</p><p>
 <a class='button button-secondary' href='//".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'] ."&action=refresh'><i class='dashicons dashicons-controls-repeat'></i> Refresh Tests</a> Tests last updated: ".get_option('abr_last_updated','x')." </p> <p> We automatically clear your Wordpress cache if you are using any of the following plugins: W3TC, HyperCache, WP Super Cache, Comet Cache, WP Fastest Cache, Litespeed Cache or WP Rocket. <br>If you're using a different plugin you might need to <strong>flush the cache manually</strong> to see your tests live right away.</p>";
                ?>
               <!-- <input type="submit" value="Save changes" /> -->
            </form>
         </div>
      </div>
    <?php
   //  echo abrankings_show_tests();
}

function abrankings_show_tests() {
    global $wpdb;
    global $abr_tests_table_name;
    global $abr_urls_table_name;

    $sql="SELECT t.*,count(u.id) as url FROM $abr_urls_table_name u, $abr_tests_table_name t 
        WHERE u.test_id=t.id AND t.ended IS NULL group by t.id";
    $tests=$wpdb->get_results( $wpdb->prepare($sql, array()), 'ARRAY_A');
    echo "<div class='wrap'><h2>Live Tests for your site</h2>
    <br>
    <table  class='striped widefat' >
        <thead><th>Test Name</th><th>Started</th><th>Changes</th><th>URLs</th></thead>";
    foreach ($tests as $id=>$test) {
        $test['alters']=json_decode($test['alters'],TRUE);
        if (is_int($id/2)) $class='even';
        else $class='odd';
        echo "<tr style='border-bottom:solid 1px #eee' class='$class'>";
        echo "<td><a href='http://app.seoscout.com/tests/".$test['id']."' target='_blank'>".$test['name']."</a></td>";
        echo "<td>".$test['started']."</td>";
        echo "<td><table style='font-size:90%;width:100%'>";
        foreach ($test['alters']['alters'] as $alter) {
            if ($alter['selector']=='meta[name=\'description\']') $alter['selector']='meta description';
            echo "<tr><td style='width:16%'><small><strong>".$alter['selector'].':</strong></small></td><td><small>'.$alter['newValue']."</small></td></tr>";
        }
        echo "</table></td>";
        echo "<td>".$test['url']."</td>";
        echo "</tr>";
    }
    echo "</table></div>";
    // return "<pre>".print_r($tests,true)."</pre>";
}

function abr_admin_notice__error() {
    $class = 'notice notice-error';
    $message = __( $_SESSION['abr_message'] );

    if($message) printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    unset($_SESSION['abr_message']);
}

function abr_admin_notice__updated() {
    $class = 'notice updated';
    $message = __( $_SESSION['abr_message'] );

    if($message) printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    unset($_SESSION['abr_message']);
}
//add_dashboard_page('A/B Rankings','A/B Rankings','everything','abrankings','abrankings_settings');









add_action("wp_ajax_abr_save_settings", "save_settings");
add_action("wp_ajax_nopriv_abr_save_settings", "please_login");

function save_settings() {
   check_ajax_referer('_wpnonce', '_wpnonce' );

   $data = $_POST;
   // // unset($data['option_page'], $data['action'], $data['_wpnonce'], $data['_wp_http_referer']);


   // // if ( update_option('abr_token', $data['abr_token'] ) ) {
   // //    die(1);
   // // } else {
   // //    die (0);
   // // }
   update_option('abr_token', $data['abr_token'] );
   update_option('abr_id', $data['abr_id'] );

   wp_die();
}

function please_login() {
   echo "You must log in to like";
   die();
}






