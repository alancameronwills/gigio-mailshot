<?php

/**
 * Plugin Name: Gigio Mailshot
 * Description: Send email about upcoming events
 * Version: 1.6.2
 * Author: Alan Cameron Wills
 * Licence: GPLv2
 * Requires Plugins: gigiau-events-posters
 * Update URI: https://github.com/alancameronwills/gigio-mailshot
 */

// Keys file is local-only (gitignored, not in release zips). Don't fatal if it's missing.
 if (file_exists(__DIR__ . "/mailchimp-keys.php")) {
     include __DIR__ . "/mailchimp-keys.php";
 } else {
     class MailChimpKeys
     {
         const MailChimpKey = "";
         const MailChimpAuthorization = "";
         const MailChimpUrl = "";
         const FolderID = "";
     }
     add_action('admin_notices', function () {
         echo '<div class="notice notice-error"><p><strong>Gigio Mailshot:</strong> <code>mailchimp-keys.php</code> is missing from the plugin folder. Mailchimp features won't work until it is restored (see README.md).</p></div>';
     });
 }
 
class MailChimpEmail extends MailChimpKeys
{
    private $mailChimpId = 0;
    private $mailChimpWebId = "";

    private static function mailChimpApi($cmd, $body = null, $method = 'POST')
    {
        $status = 0;
        $output = [];
        try {
			//error_log("Auth: " . self::MailChimpAuthorization);
            $url = self::MailChimpUrl . $cmd;
            $args = ["headers" => ["Authorization" => self::MailChimpAuthorization]];
            //error_log("mailChimpApi " . $url);
            if ($body != null) {
                //error_log (" --- $method ===");
                $args["body"] = json_encode($body);
                $args["method"] = $method;
                //error_log(" --- " . print_r($args, true));
                $result = wp_remote_request($url, $args);
            } else {
                //error_log (" --- GET ===");
                //error_log(" --- " . print_r($args, true));
                $result = wp_remote_get(
                    $url,
                    ["headers" => ["Authorization" => self::MailChimpAuthorization]]
                );
            }
            //error_log(print_r($result, true));

            $status = wp_remote_retrieve_response_code($result);
            $output = json_decode(wp_remote_retrieve_body($result));
            if (400 <= $status) {
                error_log("mailChimpApi $cmd STATUS $status " . print_r($output, true));
            }
        } catch (Exception $e) {
            error_log("mailChimpApi $cmd EXCEPTION " . print_r($e, true));
        }
        return ["status" => $status, "body" => $output];
    }
    // https://mailchimp.com/developer/marketing/api/campaigns/
    public function __construct()
    {
        $response = self::mailChimpApi("campaigns", [
            "type" => "regular",
            "recipients" => ["list_id" => "5e14c66a74"], //Moylgrove Audience ID
            "settings" => [
                "subject_line" => "What's Up in Moylgrove",
                "preview_text" => "At the Old School Hall in the next few weeks",
                "title" => "Monthly " . date("Y-m-j H-i"),
                "from_name" => "Cymdeithas Trewyddel",
                "reply_to" => "info@moylgrove.wales",
                "to_name" => "*|FNAME|* *|LNAME|*",
                "folder_id" => self::FolderID, // monthly
                "auto_footer" => true
            ]
        ]);
        if (200 == $response['status']) {
            //error_log(" Constructor " . print_r($response['body'], true));
            $this->mailChimpId = $response['body']->id;
            $this->mailChimpWebId = self::MailChimpUrl . "campaigns/show/?id={$response['body']->web_id}";
            error_log("  Created $this->mailChimpWebId");
        }
    }

    // https://mailchimp.com/developer/marketing/api/campaign-content/
    public function setContent($html)
    {
        if (!$this->mailChimpId) return null;
        self::mailChimpApi("campaigns/$this->mailChimpId/content", [
            "html" => $html
        ], "PUT");
    }

    public function test()
    {
    	$current_user = wp_get_current_user();
		$email = $current_user->user_email;
        return self::mailChimpApi(
            "campaigns/$this->mailChimpId/actions/test",
            [
                "test_emails" => ["$email"],
                "send_type" => "html"
            ]
        );
    }

    public function send()
    {
        return self::mailChimpApi("campaigns/$this->mailChimpId/actions/send", ["send_type" => "html"]);
    }
	
	public static function ping() {
		$response = self::mailChimpApi("ping");
		return $response;
	}

    public static function clearCampaigns() {
        $response = self::mailChimpApi("campaigns?folder_id=" . self::FolderID);
            $status = $response['status'];
            $output = $response['body'];
		$deleted = 0;
		$count = 0;
        if (200 == $status) {
            forEach ($output->campaigns as $campaign) {
				$count++;
				//error_log("D {$campaign->settings->title}");
                if (isset($campaign->status) && $campaign->status != "sent"
						&& $campaign->settings->folder_id == self::FolderID) {
					// error_log("  DELETE {$campaign->settings->title} ");
                    self::mailChimpApi("campaigns/{$campaign->id}", ['x'=>''], "DELETE");
					$deleted++;
                }
            }
        }
		return ['status'=>$status, 'body'=> "Deleted $deleted / $count"];
    }
}

function sendMailChimp($html, $full = false)
{
    $mail = new MailChimpEmail();
    $mail->setContent($html);
    if ($full) {
        return $mail->send();
    } else {
        return $mail->test();
    }
}

function gigio_mailshot_get_upcoming_events()
{
    // Events are stored and queried by the gigiau-events-posters plugin (the
    // "gig" category). We reuse its own functions rather than re-implementing
    // the recurrence/approval logic here.
    if (!function_exists('gigio_get_gigs_with_recurs') || !function_exists('gigio_get_gigs')) {
        error_log("gigio_mailshot_get_upcoming_events: gigiau-events-posters plugin is not active");
        return [];
    }

    $category = defined('GIGIO_CATEGORY') ? GIGIO_CATEGORY : "gig";
    $fromDate = date('Y-m-d');
    $untilDate = date('Y-m-d', strtotime("+3 months", strtotime($fromDate)));

    // $includePending = false: only approved events, as for the public listing.
    $postDated = gigio_get_gigs_with_recurs($fromDate, $category, false);
    $postIds = array_map(function ($item) {
        return $item->ID;
    }, $postDated);
    $gigs = gigio_get_gigs($fromDate, $category, $postIds);

    $events = [];
    foreach ($gigs as $gig) {
        $meta = $gig['meta'];
        $dtstart = $meta['dtstart'] ?? '';
        if ($dtstart === '' || $dtstart > $untilDate) continue;

        $events[] = [
            "title" => gigio_decode_text($gig['title']),
            "id" => $gig['id'],
            "url" => $gig['link'],
            "src" => $gig['smallpic'],
            "content" => shortContent($gig['content']),
            "booking" => !empty($meta['bookinglink']) || !empty($meta['booklabel']),
            "booklabel" => !empty($meta['booklabel']) ? $meta['booklabel'] : "Booking essential",
            "dt" => strtotime($dtstart),
            "date" => date('D j M Y G:i', strtotime($dtstart)),
            "subtitle" => gigio_decode_text($meta['venue'] ?? ''),
            "price" => gigio_decode_text($meta['dtinfo'] ?? '')
        ];
    }
    uasort($events, "compareDates");
    return $events;
}

function compareDates($a, $b)
{
    return $a["dt"] - $b["dt"];
}

function shortContent($content)
{
    return preg_replace("/\n+/", "<br/>", trim(preg_replace(
        "/<.*?>/",
        "",
        join("\n", array_slice(preg_split("/<\/p>/", 
        	preg_replace("/\[.*/s", "", $content), 3, PREG_SPLIT_NO_EMPTY), 0, 2))
    )));
}

function eventsToHtml($events)
{
    //return print_r($events, true);
    ob_start();
?>
    <style>
        .events img {
            max-width: 350px;
            width: 100%;
        }

        .eventHead {
            font-weight: bold;
            font-size: x-large;
        }
        .eventSubHead {
            font-size: large;
        }

        .booking {
            color: darkred;
            background-color: yellow;
            font-weight: bold;
        }

        .ellipsis {
            text-align: right;
        }
    </style>
    <div class="events-wrapped">
        <h2>Coming up in Moylgrove</h2>
        <p>Mostly in the Old School Hall</p>
        <div class="events">
            <?php
            foreach ($events as $event) {
            ?>
                <hr />
                <div class="eventHead">
                    <a href="<?= $event['url'] ?>"><?= $event['title'] ?></a>
                </div>
                <div class="eventSubHead">
                    <a href="<?= $event['url'] ?>"><?= $event['subtitle'] ?></a>
                </div>
                <?php
                if ($event['booking']) {
                ?>
                    <div class="booking"><a href="<?= $event['url'] ?>"><?= $event['booklabel'] ?></a></div>
                <?php
                }
                ?>
                <div class="detail">
                    <?= $event['date'] ?><br /><?= $event['price'] ?>
                    <br />
                    <a href="<?= $event['url'] ?>"><img src="<?= $event['src'] ?>" style="max-width:350px; width:100%;" /></a>
                </div>
                <div class="eventFoot">
                    <?= $event['content'] ?>
                </div>
                <div class="ellipsis"><a href="<?= $event['url'] ?>">...</a></div>
            <?php
            }
            ?>
        </div>
        <hr />&nbsp;
        <hr />
        <h4><a href="https://moylgrove.wales/events/">More events...</a></h4>
        <h4><a href="https://moylgrove.wales/hire-the-hall/">Hire the Hall</a></h4>
        <h4><a href="https://moylgrove.wales/">Moylgrove and its history</a></h4>
    </div>

<?php
    return ob_get_clean();
}



/****  SHORTCODE  ****/

function gigio_mailshot($attributes = [])
{
    extract(
        shortcode_atts(
            [
                'sendToAll' => '',
                'sendToTest' => ''
            ],
            $attributes
        )
    );
    if (!current_user_can( 'edit_posts' )) return "<h1>Mailshot: Not logged in</h1>";
    
    error_log("gigio_mailshot");
    $events = gigio_mailshot_get_upcoming_events();
    $html = eventsToHtml($events);
    $sendCode = date('z');
	
	$cmd = (isset($_GET['ping']) ? "ping" 
		: (isset($_GET['test']) ? "test" 
			: (isset($_GET['send']) && $_GET['send']==$sendCode ? "send" 
				: "")));
	$buttons = "";
	global $wp;
    $thisPage = home_url( $wp->request );
	
	// Avoid accidental sending by browser Back or Refresh
	$buttons .= "<script>history.replaceState(null, '', '$thisPage');</script>";
	
    $buttons .= "<a href='#'><button onclick='if (confirm(\"Send ping?\")){location=\"$thisPage?ping=$sendCode\"}'>Ping MailChimp</button></a> ";
    $buttons .= "<a href='$thisPage'><button>Review content</button></a> ";
    $buttons .= "<a href='$thisPage?test=1'><button>Send test mail</button></a> ";
	if ($cmd != "send") {
		$buttons .= "<a href='#'><button class='send' onclick='if (confirm(\"Send to entire mailing list?\")){location=\"$thisPage?send=$sendCode\"}'>Broadcast mail</button></a>";
	}
	switch ($cmd) {
		case "ping": 
			echo MailChimpEmail::ping();
			break;
		case "test" :
			print_r(sendMailChimp($html));
			break;
		case "send" :
			print_r(sendMailChimp($html, true));
			break;
	}
    return 	"<div><style>button {padding: 0 10px;} button:not(:hover){color:gray !important;} .send{outline:2px solid red;}</style>$buttons</div><hr/>" . $html;

}
add_shortcode("gigio-mailshot", "gigio_mailshot");



/****  CRON  ****/

function gigio_mailshot_cron()
{
    error_log("gigio_mailshot_cron");
    $events = gigio_mailshot_get_upcoming_events();
    $html = eventsToHtml($events);
    sendMailChimp($html, true);
}

function gigio_mailshot_set_cron($reset=false, $soon=false, $in_a_month=false) {
	$existing = wp_next_scheduled('gigio_mailshot_cron_hook');
	$toggleOff = $existing && !$reset && !$soon && !$in_a_month;
	error_log("gigio_mailshot_set_cron $existing toggleOff=$toggleOff");
	$when = "";
	if ($reset || $toggleOff) {
		error_log ("  Clear");
		$when = "cron off";
		wp_clear_scheduled_hook('gigio_mailshot_cron_hook');
	}
	if (!$toggleOff) {
		$when = $soon ? 'tomorrow 2:14' : ($in_a_month ? '+1 month 2:14' : 'first monday of next month 2:14');
		error_log ("  Reset $when");
	
    	if (!wp_next_scheduled('gigio_mailshot_cron_hook')) {
    		error_log ("  Resetting $when");
	    	wp_schedule_event(strtotime( $when ), 'monthly', 'gigio_mailshot_cron_hook');
    	}
	}
	return ["status"=>200,"body"=>"$when"];
}


/****  INSTALLATION  ****/

function gigio_mailshot_install()
{
    gigio_mailshot_set_cron(false, true);
    error_log("Gigio Mailshot installed; will run monthly from " 
			  . date('Y-m-j H:i',wp_next_scheduled('gigio_mailshot_cron_hook')));
}

add_action("gigio_mailshot_cron_hook", "gigio_mailshot_cron");


function gigio_mailshot_deactivate()
{
	wp_clear_scheduled_hook('gigio_mailshot_cron_hook');
	delete_option('gigio_mailshot_nonce');
}

function gigio_mailshot_uninstall() {
}

register_activation_hook(__FILE__, 'gigio_mailshot_install');
register_deactivation_hook(__FILE__, 'gigio_mailshot_deactivate');
register_uninstall_hook(__FILE__, 'gigio_mailshot_uninstall');


//****  ADMIN PAGE *****/

add_action('wp_ajax_gigio_mailshot_ajax', 'gigio_mailshot_ajax');


function gigio_mailshot_ajax() {
	$cmd = $_POST['go'];
	error_log("gigio_mailshot_ajax $cmd");
	$ns = get_option('gigio_mailshot_nonce');
	check_ajax_referer("gigio{$ns}_mailshot");
    $events = gigio_mailshot_get_upcoming_events();
    $html = eventsToHtml($events);
	$result = ["status"=>0,"body"=>""];
	switch ($cmd) {
		case "clear": 
			$result = MailChimpEmail::clearCampaigns();
			break;
		case "ping": 
			$result = MailChimpEmail::ping();
			break;
		case "test" :
			$result = sendMailChimp($html);
			break;
		case "send" :
			$result = sendMailChimp($html, true);
			gigio_mailshot_set_cron(true, false, true);
			break;
		case "setcron": 
			$result = gigio_mailshot_set_cron();
			break;
	}
	$result["scheduled"] = wp_next_scheduled('gigio_mailshot_cron_hook');
	echo json_encode($result);
	wp_die();
}


add_action('admin_menu', 'gigio_mailshot_setup_menu');
 
function gigio_mailshot_setup_menu(){
    add_menu_page( 'Mailshot', 'Gigio Mailshot', 'manage_options', 'gigio-mailshot', 'gigio_mailshot_admin_page' );
}
 
function gigio_mailshot_admin_page(){
	$ns = date('YmjHi');
	$nonce = wp_create_nonce("gigio{$ns}_mailshot");
	update_option('gigio_mailshot_nonce', $ns);
    ?>
		<style>
			#mailshot-admin {
				max-width:400px;
			}
			.schedule {
				margin: 6px 0;
				display:flex;
				justify-content: space-between;
    			align-items: center;
			}
			button {background-color:white;}
			.events-wrapped { 
				background-color: white; 
				max-width: 100%; 
				padding:10px; 
				outline:1px solid blue; 
				font-size: 16px;
			}
			.events>div { margin: 10px;}
			.events>div.ellipsis {margin:0}
		</style>

	<div id="mailshot-admin">
    <h1>Gigio Mailshot</h1>
	<div class="schedule">
		<div id='cron'></div>
		<div><button onclick="sendAjax(this, 'setcron')">Toggle automatic</button></div>
	</div>
	<script>
		function showAjaxResponse (d) {
			const r = document.getElementById("response");
			r.innerHTML = d;
		}
		function showSchedule(ts) {
			let s = !ts ? "No automatic mailshot scheduled" : 
				"Next automatic mailshot: " + new Date(ts * 1000).toLocaleString();
			document.getElementById("cron").innerHTML = s;
		}
		showSchedule(<?= wp_next_scheduled('gigio_mailshot_cron_hook') ?>);
		function sendAjax (button, action='ping', confirmation) {
			button.disabled = true;
			setTimeout(()=>button.disabled=false, 3000);
			
			if (!confirmation || confirm(confirmation)) {
				let form = new FormData();
				form.append('action', 'gigio_mailshot_ajax');
				form.append('go', action);
				form.append('_ajax_nonce', '<?=$nonce?>');
				fetch(ajaxurl, {
						method: 'POST',
						body: form
					}).then (r=> r.json())
					.then(r => {
						if (r.status != 204) showAjaxResponse(JSON.stringify(r.body));
						if (r.status >= 200 && r.status < 300) {
							button.style.backgroundColor = "lightgreen";
							setTimeout(()=>{button.style.backgroundColor='#e0ffe0'},2000);
						} else {
							button.style.backgroundColor = "red";
							setTimeout(()=>{button.style.backgroundColor='pink'},2000);
						}
						showSchedule(r.scheduled);
					})
					.catch(e=>showAjaxResponse(e)) ;
			}
		}
		
</script>
	<div id="response"></div>
	<div style="margin: 6px 0">
		<button onclick="sendAjax(this, 'clear', 'Clear generated emails?')">Clear drafts</button>
		<button onclick="sendAjax(this, 'ping')">Ping MailChimp</button>
		<button onclick="sendAjax(this, 'test')">Send Test mail</button>
		<button style="outline:1px solid red;" onclick="sendAjax(this, 'send', 'Send mail to whole list?')">Broadcast</button>
	</div>
	
	<div>
		
    <?php
    $events = gigio_mailshot_get_upcoming_events();
    $html = eventsToHtml($events);
		echo $html;
	?>
</div></div>
    <?php
	
}

/**** GITHUB AUTO-UPDATE ****/

const GIGIO_MAILSHOT_GITHUB_REPO = 'alancameronwills/gigio-mailshot';

/** Latest GitHub release (cached 6 hours): ['version' => ..., 'zip' => ..., 'url' => ..., 'notes' => ...] or null. */
function gigio_mailshot_latest_release($force = false)
{
    $cacheKey = 'gigio_mailshot_latest_release';
    if (!$force) {
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached ?: null;
        }
    }
    $response = wp_remote_get(
        'https://api.github.com/repos/' . GIGIO_MAILSHOT_GITHUB_REPO . '/releases/latest',
        ['headers' => ['Accept' => 'application/vnd.github+json'], 'timeout' => 10]
    );
    $release = null;
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data['tag_name'])) {
            // Prefer the zip asset built by release.ps1; fall back to GitHub's source zipball
            $zip = $data['zipball_url'] ?? '';
            foreach ($data['assets'] ?? [] as $asset) {
                if (substr($asset['name'], -4) === '.zip') {
                    $zip = $asset['browser_download_url'];
                    break;
                }
            }
            $release = [
                'version' => ltrim($data['tag_name'], 'vV'),
                'zip' => $zip,
                'url' => $data['html_url'] ?? '',
                'notes' => $data['body'] ?? '',
            ];
        }
    } else {
        error_log('gigio-mailshot: update check failed');
    }
    // Cache failures briefly too, so a GitHub outage doesn't slow every admin page
    set_transient($cacheKey, $release ?: '', $release ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
    return $release;
}

// Tell WordPress about a newer release (Update URI header routes this to the hook for host "github.com")
add_filter('update_plugins_github.com', function ($update, $pluginData, $pluginFile) {
    if ($pluginFile !== plugin_basename(__FILE__)) {
        return $update;
    }
    $release = gigio_mailshot_latest_release();
    if (!$release || !$release['zip'] || version_compare($release['version'], $pluginData['Version'], '<=')) {
        return $update;
    }
    return [
        'slug' => dirname($pluginFile),
        'version' => $release['version'],
        'url' => $release['url'],
        'package' => $release['zip'],
    ];
}, 10, 3);

// Make "Check again" on the Updates screen bypass the cache
add_action('delete_site_transient_update_plugins', function () {
    delete_transient('gigio_mailshot_latest_release');
});

// GitHub zips of source archives unpack to "owner-repo-hash/"; make sure the folder name stays put
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra) {
    global $wp_filesystem;
    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
        return $source;
    }
    $wanted = trailingslashit($remote_source) . dirname(plugin_basename(__FILE__)) . '/';
    if (trailingslashit($source) !== $wanted && $wp_filesystem->move($source, $wanted)) {
        return $wanted;
    }
    return $source;
}, 10, 4);


// Updates replace the whole plugin folder, and the release zip doesn't contain the local keys file:
// stash it before the update and put it back afterwards.
add_filter('upgrader_pre_install', function ($response, $hook_extra) {
    if (($hook_extra['plugin'] ?? '') === plugin_basename(__FILE__) && file_exists(__DIR__ . '/mailchimp-keys.php')) {
        copy(__DIR__ . '/mailchimp-keys.php', get_temp_dir() . 'gigio-mailshot-keys.php');
    }
    return $response;
}, 10, 2);

add_filter('upgrader_post_install', function ($response, $hook_extra, $result) {
    $stash = get_temp_dir() . 'gigio-mailshot-keys.php';
    if (($hook_extra['plugin'] ?? '') === plugin_basename(__FILE__) && file_exists($stash)) {
        if (!empty($result['destination']) && copy($stash, trailingslashit($result['destination']) . 'mailchimp-keys.php')) {
            unlink($stash);
        } else {
            error_log('gigio-mailshot: could not restore mailchimp-keys.php; backup left at ' . $stash);
        }
    }
    return $response;
}, 10, 3);


// "Check for updates" link on the Plugins screen
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    if (current_user_can('update_plugins')) {
        $url = wp_nonce_url(admin_url('plugins.php?gigio_mailshot_check=1'), 'gigio_mailshot_check');
        $links[] = '<a href="' . esc_url($url) . '">Check for updates</a>';
    }
    return $links;
});

add_action('admin_init', function () {
    if (empty($_GET['gigio_mailshot_check']) || !current_user_can('update_plugins')) {
        return;
    }
    check_admin_referer('gigio_mailshot_check');
    delete_site_transient('update_plugins'); // also clears our release cache
    wp_update_plugins();
    wp_safe_redirect(admin_url('plugins.php?gigio_mailshot_checked=1'));
    exit;
});

add_action('admin_notices', function () {
    if (empty($_GET['gigio_mailshot_checked'])) {
        return;
    }
    $updates = get_site_transient('update_plugins');
    $found = isset($updates->response[plugin_basename(__FILE__)]);
    echo '<div class="notice notice-info is-dismissible"><p>Gigio Mailshot: '
        . ($found ? 'a new version is available - use "update now" in the plugin list.' : 'you have the latest version.')
        . '</p></div>';
});
