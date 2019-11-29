<?php
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/sdk_twilio.php";
	require_once $rootpath . "/support/request.php";
	require_once $rootpath . "/support/db.php";
	require_once $rootpath . "/support/db_sqlite.php";

	require_once $rootpath . "/settings.php";

	$twilio = new TwilioSDK();
	if ($twilio_apibase !== false)  $twilio->SetAccessInfo($twilio_sid, $twilio_token, $twilio_apibase);
	else  $twilio->SetAccessInfo($twilio_sid, $twilio_token);

	$managesecret = TwilioSDK::hash_hmac_internal("sha1", $rootpath . "/manage.php?ts=" . (int)(time() / (15 * 24 * 60 * 60)), $twilio_token);

	function LoadCallInfo($info)
	{
		$defaults = array(
			"ts" => 0, "details" => array(), "rec" => "", "rec_duration" => 0, "status" => "New"
		);

		return $info + $defaults;
	}

	function LoadPhoneInfo($info)
	{
		$defaults = array(
			"phone" => "", "format_phone" => "", "name" => "", "location" => "", "forward_until" => 0, "forward_to" => "", "assigned_to" => "", "status" => "Normal", "notes" => ""
		);

		return $info + $defaults;
	}

	function SaveConfig()
	{
		global $rootpath, $config;

		file_put_contents($rootpath . "/files/config.dat", json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
	}

	// Load config.
	$config = array();
	if (file_exists($rootpath . "/files/config.dat"))  $config = @json_decode(file_get_contents($rootpath . "/files/config.dat"), true);
	if (!is_array($config))  $config = array();

	$defaults = array(
		"db_ver" => 0, "last_cleanup" => 0,
		"company_name" => "",
		"max_calls_per_day" => 5, "max_recordings_per_day" => 3, "max_call_log_days" => 7, "max_recording_days" => 7,
		"start_call" => "<Say>Hello.  You've reached XYZ.  Please wait a moment.</Say>\n<Play digits=\"w2\" />\n<Pause length=\"1\" />",
		"voicemail_route" => "<Play>support/ringing.mp3</Play>\n<Pause length=\"1\" />\n<Say>Unfortunately, there's no one available to take your call.  We recognize that your time is valuable.  Please leave your name and a brief message and we'll call you back shortly.  Also, add this phone number to your contacts list so we can return your call.</Say>",
		"too_many_calls" => "<Say>Too many calls have been made from your phone to this number today.  Try again tomorrow.</Say>",
		"callback_forward" => 0, "completed_forward" => 0
	);

	$config = $config + $defaults;

	function UpgradeDB()
	{
		global $config, $db, $dbver;

		try
		{
			if (!$config["db_ver"])
			{
				// Brand new database.
				if (!$db->TableExists("phones"))
				{
					$db->Query("CREATE TABLE", array("phones", array(
						"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
						"phone" => array("STRING", 1, 25, "NOT NULL" => true),
						"assignedto" => array("STRING", 1, 25, "NOT NULL" => true),
						"status" => array("STRING", 1, 50, "NOT NULL" => true),
						"lastcalled" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
						"info" => array("STRING", 3, "NOT NULL" => true),
					),
					array(
						array("KEY", array("phone"), "NAME" => "phone"),
						array("KEY", array("assignedto"), "NAME" => "assignedto")
					)));
				}

				if (!$db->TableExists("calls"))
				{
					$db->Query("CREATE TABLE", array("calls", array(
						"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
						"pid" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
						"sid" => array("STRING", 1, 255, "NOT NULL" => true),
						"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
						"status" => array("STRING", 1, 50, "NOT NULL" => true),
						"voicemail" => array("INTEGER", 1, "UNSIGNED" => true, "NOT NULL" => true),
						"info" => array("STRING", 3, "NOT NULL" => true),
					),
					array(
						array("KEY", array("pid", "sid"), "NAME" => "pid_sid"),
						array("KEY", array("pid", "status", "voicemail"), "NAME" => "pid_status_voicemail")
					)));
				}

				$config["last_vacuum"] = time();
				$config["db_ver"] = $dbver;
			}

			SaveConfig();
		}
		catch (Exception $e)
		{
			echo "An error occurred while upgrading the database.  " . $e->getMessage();

			exit();
		}
	}

	// Connect to the database.
	$dbfilename = $rootpath . "/files/main_" . TwilioSDK::hash_hmac_internal("sha1", "db", $twilio_token) . ".db";
	$dbver = 1;

	if (!file_exists($dbfilename))  $config["db_ver"] = 0;

	try
	{
		$db = new CSDB_sqlite();
		$db->Connect("sqlite:" . $dbfilename);
		$db->Query("USE", "vm");
	}
	catch (Exception $e)
	{
		echo "An error occurred while connecting to the database.";

		exit();
	}

	if ($config["db_ver"] < $dbver)  UpgradeDB();

	// Occasional call log cleanup.
	if ($config["last_cleanup"] < time() - 12 * 60 * 60)
	{
		$ts = time();
		$recordts = $ts - $config["max_recording_days"] * 24 * 60 * 60;
		$logts = $ts - $config["max_call_log_days"] * 24 * 60 * 60;

		$db->BeginTransaction();

		// Old calls and recordings.
		$removeids = array();

		$result = $db->Query("SELECT", array(
			"*",
			"FROM" => "?",
			"WHERE" => "created < ?"
		), "calls", $recordts);

		while ($row = $result->NextRow())
		{
			$info = LoadCallInfo(json_decode($row->info, true));

			if ($info["ts"] < $recordts && $info["rec"] !== "")
			{
				@unlink($rootpath . "/files/record_" . $row->id . "_" . $info["rec"] . ".mp3");

				$info["rec"] = "";
				$info["rec_duration"] = 0;

				if ($info["ts"] >= $logts)
				{
					$db->Query("UPDATE", array("calls", array(
						"voicemail" => 0,
						"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
					), "WHERE" => "id = ?"), $row->id);
				}
			}

			if ($info["ts"] < $logts)  $removeids[] = $row->id;
		}

		if (count($removeids))  $db->Query("DELETE", array("calls", "WHERE" => "id IN (" . implode(",", $removeids) . ")"));

		// Phones.
		$removeids = array();
		$ts = time();

		$result = $db->Query("SELECT", array(
			"p.*",
			"FROM" => "? AS p LEFT OUTER JOIN ? AS c ON (p.id = c.pid)",
			"WHERE" => "c.pid IS NULL AND p.status = 'Normal'"
		), "phones", "calls");

		while ($row = $result->NextRow())
		{
			$info = LoadPhoneInfo(json_decode($row->info, true));

			if ($info["forward_until"] > 0 && $info["forward_until"] < $ts)  $info["forward_until"] = 0;

			if ($info["forward_until"] == 0)  $removeids[] = $row->id;
		}

		if (count($removeids))  $db->Query("DELETE", array("phones", "WHERE" => "id IN (" . implode(",", $removeids) . ")"));

		$db->Commit();

		// Minimize the database.
		try
		{
			$db->Query(false, "VACUUM");
		}
		catch (Exception $e)
		{
		}

		$config["last_cleanup"] = $ts;

		SaveConfig();
	}
?>