<?php
	// Voice Manager.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	require_once "support/str_basics.php";
	require_once "support/page_basics.php";

	Str::ProcessAllInput();

	// $bb_randpage is used in combination with a user token to prevent hackers from sending malicious URLs.
	$bb_randpage = "cfc3bb4fe39567478ea5243163c5c33a06a88b63";

	require_once "base.php";

	$bb_rootname = trim($config["company_name"] . " Voicemail Manager");

	session_start();

	if (!isset($_SESSION["phone"]) || !isset($allowedphones[$_SESSION["phone"]]))
	{
		if (!isset($_REQUEST["t1"]) || !isset($_REQUEST["t2"]) || !isset($allowedphones[$_REQUEST["t1"]]) || Str::CTstrcmp(TwilioSDK::hash_hmac_internal("sha1", $_REQUEST["t1"], $managesecret), $_REQUEST["t2"]) != 0)
		{
			// For emergency access to this tool.
//			$keys = array_keys($allowedphones);
//			header("Location: " . BB_GetFullRequestURLBase() . "?t1=" . urlencode($keys[0]) . "&t2=" . TwilioSDK::hash_hmac_internal("sha1", $keys[0], $managesecret));
//			exit();

			$contentopts = array(
				"desc" => "Invalid URL supplied."
			);

			BB_GeneratePage("Access Denied", array(), $contentopts);
		}

		require_once $rootpath . "/support/random.php";

		$rng = new CSPRNG();

		$_SESSION["phone"] = $_REQUEST["t1"];
		$_SESSION["token"] = $rng->GenerateString();

		header("Location: " . BB_GetFullRequestURLBase());
	}

	$bb_usertoken = $_SESSION["token"];


	BB_ProcessPageToken("action");

	// Menu/Navigation options.
	$menuopts = array(
		"Main Menu" => array(
			"New Voicemails" => BB_GetRequestURLBase() . "?action=calllog&show=new_voicemails&sec_t=" . BB_CreateSecurityToken("calllog"),
			"My Assignments" => BB_GetRequestURLBase() . "?action=calllog&show=assignments&sec_t=" . BB_CreateSecurityToken("calllog"),
			"Call Log" => BB_GetRequestURLBase() . "?action=calllog&sec_t=" . BB_CreateSecurityToken("calllog"),
		)
	);

	if ($allowedphones[$_SESSION["phone"]]["admin"])
	{
		$menuopts["Main Menu"]["Manage Media"] = BB_GetRequestURLBase() . "?action=managemedia&sec_t=" . BB_CreateSecurityToken("managemedia");
		$menuopts["Main Menu"]["Edit Configuration"] = BB_GetRequestURLBase() . "?action=editconfig&sec_t=" . BB_CreateSecurityToken("editconfig");
	}

	// Customize styles.
	function BB_InjectLayoutHead()
	{
		// Menu title underline:  Colors with 60% saturation and 75% brightness generally look good.
?>
<style type="text/css">
#menuwrap .menu .title { border-bottom: 2px solid #CC3339; }
</style>
<?php

		// Keep PHP sessions alive.
		if (session_status() === PHP_SESSION_ACTIVE)
		{
?>
<script type="text/javascript">
setInterval(function() {
	jQuery.post('<?=BB_GetRequestURLBase()?>', {
		'action': 'heartbeat',
		'sec_t': '<?=BB_CreateSecurityToken("heartbeat")?>'
	});
}, 5 * 60 * 1000);
</script>
<?php
		}
	}

	if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "heartbeat")
	{
		$_SESSION["lastts"] = time();

		echo "OK";

		exit();
	}

	// Call log actions.
	if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "handledcaller")
	{
		$id = (isset($_REQUEST["id"]) ? (int)$_REQUEST["id"] : 0);

		$db->BeginTransaction();

		$result = $db->Query("SELECT", array(
			"*",
			"FROM" => "?",
			"WHERE" => "pid = ? AND status = 'New'"
		), "calls", $id);

		while ($row = $result->NextRow())
		{
			$info = LoadCallInfo(json_decode($row->info, true));

			$info["status"] = "Handled";

			$db->Query("UPDATE", array("calls", array(
				"status" => $info["status"],
				"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
			), "WHERE" => "id = ?"), $row->id);
		}

		$db->Commit();

		BB_RedirectPage("success", "Successfully updated call statuses.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "editphone")
	{
		$id = (isset($_REQUEST["id"]) ? (int)$_REQUEST["id"] : 0);

		$row = $db->GetRow("SELECT", array(
			"*",
			"FROM" => "?",
			"WHERE" => "id = ?"
		), "phones", $id);

		if (!$row)  BB_RedirectPage("error", "The selected phone doesn't exist.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));

		$info = LoadPhoneInfo(json_decode($row->info, true));

		if ($info["forward_until"] > 0 && $info["forward_until"] < time())
		{
			$info["forward_until"] = 0;
			$info["forward_to"] = "";
		}

		$phonemap = array();
		$assignmap = array();
		if (!isset($allowedphones[$info["forward_to"]]))  $phonemap[$info["forward_to"]] = $info["forward_to"];
		if (!isset($allowedphones[$info["assigned_to"]]))  $assignmap[$info["assigned_to"]] = $info["assigned_to"];
		foreach ($allowedphones as $phone => $info2)
		{
			$phonemap[$phone] = $info2["name"] . " (" . $phone . ")";
			$assignmap[$phone] = $info2["name"] . " (" . $phone . ")";
		}

		unset($phonemap[""]);
		if ($info["phone"] === $_SESSION["phone"])  unset($phonemap[$_SESSION["phone"]]);

		if (isset($_REQUEST["format_phone"]))
		{
			if ($_REQUEST["forward_mode"] !== "0" && !isset($phonemap[$_REQUEST["forward_to"]]))  BB_SetPageMessage("error", "Please select an option for 'Forward To'.", "forward_to");
			if ($_REQUEST["forward_mode"] === "" && FlexFormsExtras::ParseDateTime($_REQUEST["forward_until_date"], $_REQUEST["forward_until_time"]) === false)  BB_SetPageMessage("error", "Please enter a valid date and time for 'Forward Until'.", "forward_mode");
			if (!isset($assignmap[$_REQUEST["assigned_to"]]))  BB_SetPageMessage("error", "Please select an option for 'Assigned To'.", "assigned_to");

			if (BB_GetPageMessageType() != "error")
			{
				$info["format_phone"] = $_REQUEST["format_phone"];
				$info["name"] = $_REQUEST["name"];
				$info["location"] = $_REQUEST["location"];
				$info["forward_to"] = $_REQUEST["forward_to"];
				$info["forward_until"] = (int)($_REQUEST["forward_mode"] === "" ? FlexFormsExtras::ParseDateTime($_REQUEST["forward_until_date"], $_REQUEST["forward_until_time"]) : $_REQUEST["forward_mode"]);
				$info["assigned_to"] = $_REQUEST["assigned_to"];
				$info["status"] = $_REQUEST["status"];
				$info["notes"] = $_REQUEST["notes"];

				if ($info["forward_until"] > 0 && $info["forward_until"] < time())
				{
					$info["forward_until"] = 0;
					$info["forward_to"] = "";
				}

				$db->Query("UPDATE", array("phones", array(
					"assignedto" => $info["assigned_to"],
					"status" => $info["status"],
					"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
				), "WHERE" => "id = ?"), $id);

				BB_RedirectPage("success", "Successfully updated the phone information.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));
			}
		}

		$desc = "<br>";
		ob_start();
?>
<style type="text/css">
.forwarding { display: none; }
.forward_until { display: none; }
</style>

<script type="text/javascript">
function UpdateForwardingDisplay()
{
	var mode = $('select[name=forward_mode]').val();

	if (mode === '0')  $('.forwarding').hide();
	else if (mode === '-1')
	{
		$('.forwarding').show();
		$('.forward_until').hide();
	}
	else
	{
		$('.forwarding').show();
		$('.forward_until').show();
	}
}

$(function() {
	$('select[name=forward_mode]').change(UpdateForwardingDisplay).keyup(UpdateForwardingDisplay);

	UpdateForwardingDisplay();
});
</script>
<?php
		$desc .= ob_get_contents();
		ob_end_clean();

		$contentopts = array(
			"desc" => "Edit the phone information for " . $info["phone"] . ".",
			"htmldesc" => $desc,
			"hidden" => array(
				"id" => $id,
				"show" => $_REQUEST["show"]
			),
			"fields" => array(
				array(
					"title" => "Formatted Number",
					"width" => "38em",
					"type" => "text",
					"name" => "format_phone",
					"default" => $info["format_phone"],
					"desc" => "When this field is empty, the E.164 number is displayed.  Automatically set when a voicemail arrives."
				),
				array(
					"title" => "Name",
					"width" => "38em",
					"type" => "text",
					"name" => "name",
					"default" => $info["name"],
					"desc" => "The name associated with this number.  Automatically set when a call arrives if the Caller Name Lookup/CNAM database service is enabled."
				),
				array(
					"title" => "Location",
					"width" => "38em",
					"type" => "text",
					"name" => "location",
					"default" => $info["location"],
					"desc" => "The location associated with this number.  Automatically set when a call arrives."
				),
				array(
					"title" => "Forwarding",
					"width" => "38em",
					"type" => "select",
					"name" => "forward_mode",
					"options" => array("0" => "Never - Always send the caller to voicemail", "" => "Temporary - Forward to a specific user until a set date and time", "-1" => "Always - Always forward to a specific user"),
					"default" => ($info["forward_until"] < 1 ? $info["forward_until"] : ""),
					"desc" => "Manage forwarding options for this number."
				),
				"html:<div class=\"forwarding\">",
				"html:<div class=\"forward_until\">",
				"startrow",
				array(
					"title" => "Forward Until",
					"width" => "18em",
					"type" => "date",
					"name" => "forward_until_date",
					"default" => ($info["forward_until"] > 0 ? date("Y-m-d", $info["forward_until"]) : date("Y-m-d")),
					"desc" => "Specify forwarding expiry."
				),
				array(
					"htmltitle" => "&nbsp;",
					"width" => "17em",
					"type" => "text",
					"name" => "forward_until_time",
					"default" => ($info["forward_until"] > 0 ? date("g:i a", $info["forward_until"]) : date("g:i a")),
				),
				"endrow",
				"html:</div>",
				array(
					"title" => "Forward To",
					"width" => "38em",
					"type" => "select",
					"name" => "forward_to",
					"options" => $phonemap,
					"default" => $info["forward_to"],
					"desc" => "Foward calls to the specified user."
				),
				"html:</div>",
				array(
					"title" => "Assigned To",
					"width" => "38em",
					"type" => "select",
					"name" => "assigned_to",
					"options" => $assignmap,
					"default" => $info["assigned_to"],
					"desc" => "Who the caller is currently assigned to."
				),
				array(
					"title" => "Status",
					"width" => "38em",
					"type" => "select",
					"name" => "status",
					"options" => array("Normal" => "Normal - Allows calls and voicemails", "Block" => "Block - Blocks this number and rejects any calls"),
					"default" => $info["status"],
					"desc" => "The status for the caller.  Useful for blocking abusive/toxic people."
				),
				array(
					"title" => "Notes",
					"width" => "38em",
					"type" => "textarea",
					"name" => "notes",
					"default" => $info["notes"]
				),
			),
			"submit" => "Save"
		);

		BB_GeneratePage("Edit Phone", $menuopts, $contentopts);
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "selfassign" && isset($_REQUEST["currassign"]))
	{
		$id = (isset($_REQUEST["id"]) ? (int)$_REQUEST["id"] : 0);

		$row = $db->GetRow("SELECT", array(
			"*",
			"FROM" => "?",
			"WHERE" => "id = ? AND assignedto = ?"
		), "phones", $id, $_REQUEST["currassign"]);

		if (!$row)  BB_RedirectPage("error", "Someone else took the assignment.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));

		$info = LoadPhoneInfo(json_decode($row->info, true));

		$info["assigned_to"] = $_SESSION["phone"];

		$db->Query("UPDATE", array("phones", array(
			"assignedto" => $info["assigned_to"],
			"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
		), "WHERE" => "id = ? AND assignedto = ?"), $row->id, $_REQUEST["currassign"]);

		BB_RedirectPage("success", "Successfully took the assignment.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "completeassignment")
	{
		$id = (isset($_REQUEST["id"]) ? (int)$_REQUEST["id"] : 0);

		$row = $db->GetRow("SELECT", array(
			"*",
			"FROM" => "?",
			"WHERE" => "id = ? AND assignedto = ?"
		), "phones", $id, $_SESSION["phone"]);

		if (!$row)  BB_RedirectPage("error", "Invalid request.  Can't complete an assignment not assigned to you.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));

		$info = LoadPhoneInfo(json_decode($row->info, true));

		if ($config["completed_forward"] != 0 && $info["phone"] !== $_SESSION["phone"] && $info["forward_until"] > -1)
		{
			$info["forward_to"] = $_SESSION["phone"];
			$info["forward_until"] = time() + $config["completed_forward"] * 60;
		}

		$info["assigned_to"] = "";

		$db->BeginTransaction();

		$db->Query("UPDATE", array("phones", array(
			"assignedto" => $info["assigned_to"],
			"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
		), "WHERE" => "id = ?"), $row->id);

		$result = $db->Query("SELECT", array(
			"*",
			"FROM" => "?",
			"WHERE" => "pid = ? AND status = 'New'"
		), "calls", $row->id);

		while ($row2 = $result->NextRow())
		{
			$cinfo = LoadCallInfo(json_decode($row2->info, true));

			$cinfo["status"] = "Handled";

			$db->Query("UPDATE", array("calls", array(
				"status" => $cinfo["status"],
				"info" => json_encode($cinfo, JSON_UNESCAPED_SLASHES)
			), "WHERE" => "id = ?"), $row2->id);
		}

		$db->Commit();

		BB_RedirectPage("success", "Successfully completed the assignment.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "cancelassignment")
	{
		$id = (isset($_REQUEST["id"]) ? (int)$_REQUEST["id"] : 0);

		$row = $db->GetRow("SELECT", array(
			"*",
			"FROM" => "?",
			"WHERE" => "id = ? AND assignedto = ?"
		), "phones", $id, $_SESSION["phone"]);

		if (!$row)  BB_RedirectPage("error", "Invalid request.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));

		$info = LoadPhoneInfo(json_decode($row->info, true));

		$info["assigned_to"] = "";

		$db->Query("UPDATE", array("phones", array(
			"assignedto" => $info["assigned_to"],
			"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
		), "WHERE" => "id = ?"), $row->id);

		BB_RedirectPage("success", "Successfully cancelled the assignment.", array("action=calllog&show=" . urlencode($_REQUEST["show"]) . "&sec_t=" . BB_CreateSecurityToken("calllog")));
	}

	// Call log.
	if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "calllog")
	{
		if (isset($_REQUEST["show"]) && $_REQUEST["show"] === "assignments")  $show = "assignments";
		else if (isset($_REQUEST["show"]) && $_REQUEST["show"] === "new_voicemails")  $show = "new_voicemails";
		else if (isset($_REQUEST["show"]) && $_REQUEST["show"] === "unhandled_voicemails")  $show = "unhandled_voicemails";
		else if (isset($_REQUEST["show"]) && $_REQUEST["show"] === "all_voicemails")  $show = "all_voicemails";
		else if (isset($_REQUEST["show"]) && $_REQUEST["show"] === "new")  $show = "new";
		else  $show = "all";

		$logs = array();

		if ($show === "assignments")
		{
			$result = $db->Query("SELECT", array(
				"c.*, p.info AS pinfo",
				"FROM" => "? AS p, ? AS c",
				"WHERE" => "p.id = c.pid AND p.assignedto = ? AND c.created > ?",
				"ORDER BY" => "c.pid, c.created"
			), "phones", "calls", $_SESSION["phone"], time() - 7 * 24 * 60 * 60);
		}
		else
		{
			$result = $db->Query("SELECT", array(
				"c.*, p.info AS pinfo",
				"FROM" => "? AS p, ? AS c",
				"WHERE" => "p.id = c.pid" . ($show === "new_voicemails" ? " AND p.assignedto = ''" : "") . ($show === "new_voicemails" || $show === "unhandled_voicemails" || $show === "new" ? " AND c.status = 'New'" : "") . ($show === "new_voicemails" || $show === "unhandled_voicemails" || $show === "all_voicemails" ? " AND c.voicemail = 1" : ""),
				"ORDER BY" => "c.pid, c.created"
			), "phones", "calls");
		}

		$lastpid = false;
		$lastinfo = false;
		$lastts = false;
		$disp = "";

		while ($row = $result->NextRow())
		{
			$info = LoadPhoneInfo(json_decode($row->pinfo, true));
			$cinfo = LoadCallInfo(json_decode($row->info, true));

			if ($lastpid !== $row->pid)
			{
				if ($lastinfo !== false)
				{
					if (!isset($logs[$lastts]))  $logs[$lastts] = array();

					$logs[$lastts][] = array(
						"title" => ($lastinfo["format_phone"] !== "" ? $lastinfo["format_phone"] : $lastinfo["phone"]) . ($lastinfo["name"] !== "" ? " (" . $lastinfo["name"] . ")" : ""),
						"type" => "custom",
						"value" => $disp,
						"htmldesc" => implode(" | ", $lastoptions)
					);
				}

				$lastpid = $row->pid;
				$lastinfo = $info;

				if ($info["forward_until"] > 0 && $info["forward_until"] < time())
				{
					$info["forward_until"] = 0;
					$info["forward_to"] = "";
				}

				$disp = "<div class=\"locationwrap\">" . htmlspecialchars($info["location"]) . "</div>";
				if ($info["status"] !== "Normal")  $disp .= "<div class=\"statuswrap abnormalstatus\">" . htmlspecialchars($info["status"]) . "</div>";
				else if ($info["forward_until"] != 0 && $info["forward_to"] !== "")  $disp .= "<div class=\"statuswrap\">Forwarding inbound calls to " . htmlspecialchars($info["forward_to"] . (isset($allowedphones[$info["forward_to"]]) ? " (" . $allowedphones[$info["forward_to"]]["name"] . ")" : "") . ($info["forward_until"] > 0 ? " until " . date("M j, Y, g:i a", $info["forward_until"]) : "")) . ".</div>";

				if ($info["assigned_to"] !== "")  $disp .= "<div class=\"statuswrap\">Assigned to " . htmlspecialchars((isset($allowedphones[$info["assigned_to"]]) ? $allowedphones[$info["assigned_to"]]["name"] : $info["assigned_to"])) . ".</div>";

				$lastoptions = array();
				if ($show !== "assignments")  $lastoptions[] = "<a href=\"" . BB_GetRequestURLBase() . "?action=handledcaller&id=" . $row->pid . "&show=" . urlencode($show) . "&sec_t=" . BB_CreateSecurityToken("handledcaller") . "\" title=\"Mark all new calls/voicemails for this phone number as handled.\">Handled</a>";
				$lastoptions[] = "<a href=\"" . BB_GetRequestURLBase() . "?action=editphone&id=" . $row->pid . "&show=" . urlencode($show) . "&sec_t=" . BB_CreateSecurityToken("editphone") . "\">Edit</a>";
				if ($show !== "assignments")  $lastoptions[] = "<a href=\"" . BB_GetRequestURLBase() . "?action=selfassign&id=" . $row->pid . "&currassign=" . urlencode($info["assigned_to"]) . "&show=" . urlencode($show) . "&sec_t=" . BB_CreateSecurityToken("selfassign") . "\">Take assignment</a>";
				if ($show === "assignments")
				{
					$lastoptions[] = "<a href=\"" . BB_GetRequestURLBase() . "?action=completeassignment&id=" . $row->pid . "&show=" . urlencode($show) . "&sec_t=" . BB_CreateSecurityToken("completeassignment") . "\" title=\"Mark all calls/voicemails for this phone number as handled and complete the assignment.\">Complete</a>";
					$lastoptions[] = "<a href=\"" . BB_GetRequestURLBase() . "?action=cancelassignment&id=" . $row->pid . "&show=" . urlencode($show) . "&sec_t=" . BB_CreateSecurityToken("cancelassignment") . "\" title=\"Return the assignment to the queue.\" onclick=\"return confirm('Are you sure you want to cancel this assignment?');\">Cancel</a>";
				}
			}

			$disp .= "<div class=\"callwrap\">";
			if ($cinfo["status"] === "Handled")  $disp .= "<span class=\"voice-manager-icon voice-manager-icon--handled\" title=\"This call has been marked as handled.\"></span>";
			else if ($cinfo["status"] === "Sent Message")  $disp .= "<span class=\"voice-manager-icon voice-manager-icon--sent_message\" title=\"Sent message in response to call.\"></span>";
			else if ($cinfo["status"] === "Routed" && isset($cinfo["details"]["RouteTarget"]))  $disp .= "<span class=\"voice-manager-icon voice-manager-icon--outbound_call\" title=\"Call was routed to " . htmlspecialchars($cinfo["details"]["RouteTarget"]) . ".\"></span>";
			else if ($cinfo["rec"] !== "")  $disp .= "<span class=\"voice-manager-icon voice-manager-icon--voicemail\" title=\"Caller left a voicemail.\"></span>";
			else  $disp .= "<span class=\"voice-manager-icon voice-manager-icon--inbound_call\" title=\"Caller did not leave a voicemail.\"></span>";

			$disp .= " " . htmlspecialchars(date("M j, Y, g:i a", $cinfo["ts"]));

			if ($cinfo["rec"] !== "")
			{
				$mins = (int)($cinfo["rec_duration"] / 60);
				$secs = $cinfo["rec_duration"] % 60;

				$disp .= " (<a href=\"files/record_" . $row->id . "_" . $cinfo["rec"] . ".mp3\" data-preview-type=\"audio/mpeg\" title=\"Play voicemail\">" . $mins . ":" . sprintf("%02d", $secs) . "</a>)";
			}

			$disp .= "</div>\n";

			$lastts = $cinfo["ts"];
		}

		if ($lastinfo !== false)
		{
			if (!isset($logs[$lastts]))  $logs[$lastts] = array();

			$logs[$lastts][] = array(
				"title" => ($lastinfo["format_phone"] !== "" ? $lastinfo["format_phone"] : $lastinfo["phone"]) . ($lastinfo["name"] !== "" ? " (" . $lastinfo["name"] . ")" : ""),
				"type" => "custom",
				"value" => $disp,
				"htmldesc" => "<span class=\"nowrap\">" . implode(" |</span> <span class=\"nowrap\">", $lastoptions) . "</span>"
			);
		}

		krsort($logs);

		if ($show === "all")
		{
			$logs[0] = array();

			$result = $db->Query("SELECT", array(
				"p.*",
				"FROM" => "? AS p LEFT OUTER JOIN ? AS c ON (p.id = c.pid)",
				"WHERE" => "c.pid IS NULL"
			), "phones", "calls");

			while ($row = $result->NextRow())
			{
				$info = LoadPhoneInfo(json_decode($row->info, true));

				if ($info["forward_until"] > 0 && $info["forward_until"] < time())
				{
					$info["forward_until"] = 0;
					$info["forward_to"] = "";
				}

				$disp = "<div class=\"locationwrap\">" . htmlspecialchars($info["location"]) . "</div>";
				if ($info["status"] !== "Normal")  $disp .= "<div class=\"statuswrap abnormalstatus\">" . htmlspecialchars($info["status"]) . "</div>";
				else if ($info["forward_until"] != 0 && $info["forward_to"] !== "")  $disp .= "<div class=\"statuswrap\">Forwarding inbound calls to " . htmlspecialchars($info["forward_to"] . (isset($allowedphones[$info["forward_to"]]) ? " (" . $allowedphones[$info["forward_to"]]["name"] . ")" : "") . ($info["forward_until"] > 0 ? " until " . date("M j, Y, g:i a", $info["forward_until"]) : "")) . ".</div>";

				if ($info["assigned_to"] !== "")  $disp .= "<div class=\"statuswrap\">Assigned to " . htmlspecialchars((isset($allowedphones[$info["assigned_to"]]) ? $allowedphones[$info["assigned_to"]]["name"] : $info["assigned_to"])) . ".</div>";

				$options = array();
				if ($info["phone"] !== $_SESSION["phone"])  $options[] = "<a href=\"" . BB_GetRequestURLBase() . "?action=preparecall&id=" . $row->id . "&show=" . urlencode($show) . "&sec_t=" . BB_CreateSecurityToken("preparecall") . "\" title=\"Set your phone to forward a call to this number the next time you call in.\">Prepare call</a>";
				$options[] = "<a href=\"" . BB_GetRequestURLBase() . "?action=editphone&id=" . $row->id . "&show=" . urlencode($show) . "&sec_t=" . BB_CreateSecurityToken("editphone") . "\">Edit</a>";

				$logs[0][] = array(
					"title" => ($info["format_phone"] !== "" ? $info["format_phone"] : $info["phone"]) . ($info["name"] !== "" ? " (" . $info["name"] . ")" : ""),
					"type" => "custom",
					"value" => $disp,
					"htmldesc" => "<span class=\"nowrap\">" . implode(" |</span> <span class=\"nowrap\">", $options) . "</span>"
				);
			}
		}

		$desc = "";

		$options = array();
		$options[] = ($show !== "assignments" ? "<a href=\"" . BB_GetRequestURLBase() . "?action=calllog&show=assignments&sec_t=" . BB_CreateSecurityToken("calllog") . "\">" : "") . "My Assignments" . ($show !== "assignments" ? "</a>" : "");
		$options[] = ($show !== "new_voicemails" ? "<a href=\"" . BB_GetRequestURLBase() . "?action=calllog&show=new_voicemails&sec_t=" . BB_CreateSecurityToken("calllog") . "\">" : "") . "New Voicemails" . ($show !== "new_voicemails" ? "</a>" : "");
		$options[] = ($show !== "unhandled_voicemails" ? "<a href=\"" . BB_GetRequestURLBase() . "?action=calllog&show=unhandled_voicemails&sec_t=" . BB_CreateSecurityToken("calllog") . "\">" : "") . "Unhandled Voicemails" . ($show !== "unhandled_voicemails" ? "</a>" : "");
		$options[] = ($show !== "all_voicemails" ? "<a href=\"" . BB_GetRequestURLBase() . "?action=calllog&show=all_voicemails&sec_t=" . BB_CreateSecurityToken("calllog") . "\">" : "") . "All Voicemails" . ($show !== "all_voicemails" ? "</a>" : "");
		$options[] = ($show !== "new" ? "<a href=\"" . BB_GetRequestURLBase() . "?action=calllog&show=new&sec_t=" . BB_CreateSecurityToken("calllog") . "\">" : "") . "New Calls" . ($show !== "new" ? "</a>" : "");
		$options[] = ($show !== "all" ? "<a href=\"" . BB_GetRequestURLBase() . "?action=calllog&show=all&sec_t=" . BB_CreateSecurityToken("calllog") . "\">" : "") . "All Calls" . ($show !== "all" ? "</a>" : "");
		$desc .= "<span class=\"nowrap\">" . implode(" |</span> <span class=\"nowrap\">", $options) . "</span>";

		if (!count($logs))  $desc .= "<br><br><i>No calls found.</i>";

		ob_start();
?>
<style type="text/css">
@font-face { font-family: 'voice-manager'; src: url('support/voice_manager.woff?20190925-01'); font-weight: normal; font-style: normal; font-display: block; }

[class^="voice-manager-icon--"], [class*=" voice-manager-icon--"] {
	font-family: 'voice-manager' !important;
	speak: none;
	font-style: normal;
	font-weight: normal;
	font-variant: normal;
	text-transform: none;
	line-height: 1;
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
}

.voice-manager-icon--handled:before { content: "\e876"; }
.voice-manager-icon--sent_message:before { content: "\e0c9"; }
.voice-manager-icon--outbound_call:before { content: "\e0b4"; }
.voice-manager-icon--voicemail:before { content: "\e0d9"; }
.voice-manager-icon--inbound_call:before { content: "\e0e4"; }

.statuswrap, .locationwrap { font-size: 0.95em; color: #666666; margin-bottom: 0.2em; }
.abnormalstatus { color: #A94442; font-weight: bold; }

.nowrap { white-space: nowrap; }
</style>

<link rel="stylesheet" href="support/jquery.previewurl.css" type="text/css" media="all" />
<script type="text/javascript" src="support/jquery.previewurl.js"></script>
<script type="text/javascript">
$(function() {
	$('[data-preview-type]').PreviewURL();
});
</script>
<?php
		$desc .= ob_get_contents();
		ob_end_clean();

		$contentopts = array(
			"desc" => "",
			"htmldesc" => $desc,
			"fields" => array(
			)
		);

		foreach ($logs as $fields)
		{
			foreach ($fields as $field)
			{
				$contentopts["fields"][] = "split";
				$contentopts["fields"][] = $field;
			}
		}

		BB_GeneratePage("Call Log", $menuopts, $contentopts);
	}

	// Manage media.
	if ($allowedphones[$_SESSION["phone"]]["admin"] && isset($_REQUEST["action"]) && $_REQUEST["action"] == "deletemedia")
	{
		$files = array();
		$dir = @opendir($rootpath . "/files");
		if ($dir)
		{
			while (($file = readdir($dir)) !== false)
			{
				if (substr($file, 0, 6) === "media_")  $files[$file] = true;
			}

			closedir($dir);
		}

		if (isset($_REQUEST["file"]) && isset($files[$_REQUEST["file"]]))  @unlink($rootpath . "/files/" . $_REQUEST["file"]);

		BB_RedirectPage("success", "Successfully deleted the media file.", array("action=managemedia&sec_t=" . BB_CreateSecurityToken("managemedia")));
	}
	else if ($allowedphones[$_SESSION["phone"]]["admin"] && isset($_REQUEST["action"]) && $_REQUEST["action"] == "managemedia")
	{
		$baseurl = dirname(BB_GetFullRequestURLBase()) . "/files/";

		require_once $rootpath . "/support/flex_forms_fileuploader.php";
		require_once $rootpath . "/support/flex_forms_previewurl.php";

		function ManageMedia_UploadFilename($name, $ext, $fileinfo)
		{
			global $rootpath;

			return $rootpath . "/files/media_" . date("Y-m-d") . "_" . $name . "_" . TwilioSDK::hash_hmac_internal("sha1", "media_" . date("Y-m-d") . "_" . $name, $_SESSION["token"]) . "." . $ext;
		}

		function ManageMedia_ModifyUploadResult(&$result, $filename, $name, $ext, $fileinfo)
		{
			global $baseurl;

			$pos = strrpos($filename, "/");
			$filename = substr($filename, $pos + 1);

			$file2 = substr($filename, 6);
			$file2 = substr($file2, 0, strrpos($file2, "_"));

			$result["disp"] = $file2;
			$result["file"] = $filename;
			$result["url"] = $baseurl . $filename;
		}

		$options = array(
			"allowed_exts" => "mp3",
			"filename_callback" => "ManageMedia_UploadFilename",
			"result_callback" => "ManageMedia_ModifyUploadResult"
		);

		FlexForms_FileUploader::HandleUpload("file", $options);

		// Load existing media.
		$files = array();
		$dir = @opendir($rootpath . "/files");
		if ($dir)
		{
			while (($file = readdir($dir)) !== false)
			{
				if (substr($file, 0, 6) === "media_")  $files[] = $file;
			}

			closedir($dir);
		}

		ksort($files);

		$rows = array();
		foreach ($files as $file)
		{
			$file2 = substr($file, 6);
			$file2 = substr($file2, 0, strrpos($file2, "_"));

			$rows[] = array("<a href=\"" . htmlspecialchars($baseurl . $file) . "\" data-preview-type=\"audio/mpeg\">" . htmlspecialchars($file2) . "</a>", "<a href=\"" . BB_GetRequestURLBase() . "?action=deletemedia&file=" . urlencode($file) . "&sec_t=" . BB_CreateSecurityToken("deletemedia") . "\" onclick=\"return confirm('Are you sure you want to delete this file?');\">Delete</a>");
		}

		$desc = "<br>";
		ob_start();
?>
<script type="text/javascript">
function UploadCompletedHandler(e, data)
{
	var html = '<tr>';
	html += '<td><a href="' + data.ff_info.lastresult.url + '" data-preview-type="audio/mpeg">' + data.ff_info.lastresult.disp + '</a></td>';
	html += '<td><a href="<?=BB_GetRequestURLBase()?>?action=deletemedia&file=' + encodeURIComponent(data.ff_info.lastresult.file) + '&sec_t=<?=BB_CreateSecurityToken("deletemedia")?>" onclick="return confirm(\'Are you sure you want to delete this file?\');">Delete</a></td>';
	html += '</tr>';

	$('#f0_0_media_table tbody').append(html);
	$('#f0_0_media_table').trigger('table:columnschanged');

	data.ff_info.RemoveFile();
}
</script>
<?php
		$desc .= ob_get_contents();
		ob_end_clean();

		$contentopts = array(
			"desc" => "Manage the media files for use with the various XML options in Edit Configuration.",
			"htmldesc" => $desc,
			"fields" => array(
				array(
					"title" => "Media Files",
					"type" => "table",
					"name" => "media",
					"previewurl" => true,
					"cols" => array("Filename", "Options"),
					"rows" => $rows
				),
				array(
					"title" => "Upload Audio Files",
					"type" => "file",
					"name" => "file",
					"accept" => ".mp3, audio/*",
					"multiple" => true,
					"uploader" => true,
					"uploader_options" => array("recordaudio" => true),
					"uploader_callbacks" => array("uploadcompleted" => "UploadCompletedHandler"),
					"maxchunk" => min(FlexForms_FileUploader::GetMaxUploadFileSize(), 1000000),
				),
			)
		);

		BB_GeneratePage("Manage Media", $menuopts, $contentopts);
	}

	// Edit configuration.
	if ($allowedphones[$_SESSION["phone"]]["admin"] && isset($_REQUEST["action"]) && $_REQUEST["action"] == "editconfig")
	{
		if (isset($_REQUEST["max_calls_per_day"]))
		{
			if ($_REQUEST["max_calls_per_day"] < 1)  BB_SetPageMessage("error", "Please enter a positive integer for 'Maximum Calls Per Day'.", "max_calls_per_day");
			if ($_REQUEST["max_recordings_per_day"] < 1)  BB_SetPageMessage("error", "Please enter a positive integer for 'Maximum Recordings Per Day'.", "max_recordings_per_day");
			if ($_REQUEST["max_call_log_days"] < 1)  BB_SetPageMessage("error", "Please enter a positive integer for 'Call Log Length'.", "max_call_log_days");
			if ($_REQUEST["max_recording_days"] < 1)  BB_SetPageMessage("error", "Please enter a positive integer for 'Recording Log Length'.", "max_recording_days");

			$startxml = "<" . "?xml version=\"1.0\" encoding=\"UTF-8\" ?" . ">\n<Response>\n";
			$endxml = "\n</Response>\n";

			$xml = @simplexml_load_string($startxml . $_REQUEST["start_call"] . $endxml);
			if ($xml === false)  BB_SetPageMessage("error", "Invalid XML for 'Start Call XML'.", "start_call");

			$xml = @simplexml_load_string($startxml . $_REQUEST["voicemail_route"] . $endxml);
			if ($xml === false)  BB_SetPageMessage("error", "Invalid XML for 'Voicemail Routing XML'.", "voicemail_route");

			$xml = @simplexml_load_string($startxml . $_REQUEST["too_many_calls"] . $endxml);
			if ($xml === false)  BB_SetPageMessage("error", "Invalid XML for 'Too Many Calls XML'.", "too_many_calls");

			if (BB_GetPageMessageType() != "error")
			{
				$config["company_name"] = $_REQUEST["company_name"];

				$config["max_calls_per_day"] = max((int)$_REQUEST["max_calls_per_day"], (int)$_REQUEST["max_recordings_per_day"]);
				$config["max_recordings_per_day"] = (int)$_REQUEST["max_recordings_per_day"];
				$config["max_call_log_days"] = max((int)$_REQUEST["max_call_log_days"], (int)$_REQUEST["max_recording_days"]);
				$config["max_recording_days"] = (int)$_REQUEST["max_recording_days"];

				$config["start_call"] = $_REQUEST["start_call"];
				$config["voicemail_route"] = $_REQUEST["voicemail_route"];
				$config["too_many_calls"] = $_REQUEST["too_many_calls"];

				$config["callback_forward"] = (int)$_REQUEST["callback_forward"];
				$config["completed_forward"] = (int)$_REQUEST["completed_forward"];

				SaveConfig();

				BB_RedirectPage("success", "Successfully saved the configuration.", array("action=editconfig&sec_t=" . BB_CreateSecurityToken("editconfig")));
			}
		}

		$twimldesc = "Use TwiML verbs such as <a href=\"https://www.twilio.com/docs/voice/twiml/say\" target=\"_blank\">Say</a>, <a href=\"https://www.twilio.com/docs/voice/twiml/play\" target=\"_blank\">Play</a>, and <a href=\"https://www.twilio.com/docs/voice/twiml/pause\" target=\"_blank\">Pause</a>.";

		$baseurl = dirname(BB_GetFullRequestURLBase()) . "/files/";
		$files = array();
		$dir = @opendir($rootpath . "/files");
		if ($dir)
		{
			while (($file = readdir($dir)) !== false)
			{
				if (substr($file, 0, 6) === "media_")  $files[$file] = $baseurl . $file;
			}

			closedir($dir);
		}

		$playverbs = "";
		foreach ($files as $file => $url)
		{
			$file2 = substr($file, 6);
			$file2 = substr($file2, 0, strrpos($file2, "_"));

			$playverbs .= "<br><a href=\"#\" onclick=\"AppendPlayVerb(this, '" . htmlspecialchars($url) . "');  return false;\">Append " . htmlspecialchars($file2) . ".mp3</a>";
		}

		$desc = "<br>";
		ob_start();
?>
<script type="text/javascript">
function AppendPlayVerb(obj, url)
{
	var textbox = $(obj).closest('.formitem').find('textarea');

	textbox.val(textbox.val().trim() + '\n' + '<Play>' + url + '</Play>');
}
</script>
<?php
		$desc .= ob_get_contents();
		ob_end_clean();

		$contentopts = array(
			"desc" => "Edit the configuration.",
			"htmldesc" => $desc,
			"fields" => array(
				array(
					"title" => "Company Name",
					"width" => "38em",
					"type" => "text",
					"name" => "company_name",
					"default" => $config["company_name"],
					"desc" => "The name of your company or business.  Read at the start of the main menu for admin calls and affects the title of this tool."
				),
				array(
					"title" => "Maximum Calls Per Day",
					"width" => "38em",
					"type" => "text",
					"name" => "max_calls_per_day",
					"default" => $config["max_calls_per_day"],
					"desc" => "The maximum number of calls allowed per non-forwarded phone number per day.  After this point, calls from the number are rejected and don't incur additional charges."
				),
				array(
					"title" => "Maximum Recordings Per Day",
					"width" => "38em",
					"type" => "text",
					"name" => "max_recordings_per_day",
					"default" => $config["max_recordings_per_day"],
					"desc" => "The maximum number of calls with voicemail allowed per non-forwarded phone number per day.  After this point, calls from the number are rejected and don't incur additional charges."
				),
				array(
					"title" => "Call Log Length",
					"width" => "38em",
					"type" => "text",
					"name" => "max_call_log_days",
					"default" => $config["max_call_log_days"],
					"desc" => "The number of days to store the call log for."
				),
				array(
					"title" => "Recording Log Length",
					"width" => "38em",
					"type" => "text",
					"name" => "max_recording_days",
					"default" => $config["max_recording_days"],
					"desc" => "The number of days to store voicemail recordings for."
				),
				array(
					"title" => "Start Call XML",
					"width" => "38em",
					"type" => "textarea",
					"name" => "start_call",
					"default" => $config["start_call"],
					"htmldesc" => "What the caller hears at first when they call in.  " . $twimldesc . $playverbs
				),
				array(
					"title" => "Voicemail Routing XML",
					"width" => "38em",
					"type" => "textarea",
					"name" => "voicemail_route",
					"default" => $config["voicemail_route"],
					"htmldesc" => "What the caller hears when they are routed to voicemail.  " . $twimldesc . $playverbs
				),
				array(
					"title" => "Too Many Calls XML",
					"width" => "38em",
					"type" => "textarea",
					"name" => "too_many_calls",
					"default" => $config["too_many_calls"],
					"htmldesc" => "What a non-forwarded caller hears when they have hit their per-day call limit.  They only hear this message the first time.  After that, the call is rejected and no additional charges are incurred.  " . $twimldesc . $playverbs
				),
				array(
					"title" => "Forwarding Minutes When Calling Back",
					"width" => "38em",
					"type" => "text",
					"name" => "callback_forward",
					"default" => $config["callback_forward"],
					"desc" => "The amount of time, in minutes, to forward the recipient when calling back.  Allows the recipient to directly return a call.  Useful for reducing phone tag and handling dropped calls."
				),
				array(
					"title" => "Forwarding Minutes After Completing Assignment",
					"width" => "38em",
					"type" => "text",
					"name" => "completed_forward",
					"default" => $config["completed_forward"],
					"desc" => "The amount of time, in minutes, to forward the recipient upon completing an assignment.  Allows the recipient to directly return a call.  Useful for reducing phone tag."
				),
			),
			"submit" => "Save"
		);

		BB_GeneratePage("Edit Configuration", $menuopts, $contentopts);
	}

	// Default action.  For security, this page should never actually do anything.
	$contentopts = array(
		"desc" => "Hello " . $allowedphones[$_SESSION["phone"]]["name"] . ".  Pick an option from the menu."
	);

	BB_GeneratePage("Home", $menuopts, $contentopts);
?>