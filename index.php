<?php
	require_once "base.php";

	Request::Normalize();

	// Secure the request.
	$twilio->ValidateWebhookRequest();

	$twilio->StartXMLResponse();

	function SpeechReadyPhone($phone)
	{
		return chunk_split(preg_replace('/[^0-9]/', "", $phone), 1, " ");
	}

	// Process the incoming call.
	if (!isset($_REQUEST["CallSid"]))
	{
	}
	else if (isset($_REQUEST["RecordingUrl"]))
	{
		if (isset($_REQUEST["RecordingSid"]) && isset($_REQUEST["RecordingStatus"]) && $_REQUEST["RecordingStatus"] === "completed")
		{
			// Retrieve call info.
			$result = $twilio->RunAPI("GET", "Calls/" . $_REQUEST["CallSid"]);
			if ($result["success"])
			{
				$tcinfo = $result["data"];

				try
				{
					// Load the call.
					$phone = preg_replace('/[^0-9]/', "", $tcinfo["from"]);

					$row = $db->GetRow("SELECT", array(
						"c.*, p.info AS pinfo",
						"FROM" => "? AS p, ? AS c",
						"WHERE" => "p.id = c.pid AND p.phone = ? AND c.sid = ?"
					), "phones", "calls", $phone, $_REQUEST["CallSid"]);

					if ($row !== false)
					{
						$pinfo = LoadPhoneInfo(json_decode($row->pinfo, true));
						$cinfo = LoadCallInfo(json_decode($row->info, true));

						if ($pinfo["format_phone"] === "")
						{
							$pinfo["format_phone"] = $tcinfo["from_formatted"];

							$db->Query("UPDATE", array("phones", array(
								"info" => json_encode($pinfo, JSON_UNESCAPED_SLASHES)
							), "WHERE" => "id = ?"), $row->pid);
						}

						// Download the recording.
						$result = $twilio->DownloadRecording($_REQUEST["RecordingSid"], ".mp3");
						if ($result["success"])
						{
							// Save the recording locally.
							require_once $rootpath . "/support/random.php";

							$rng = new CSPRNG();

							$cinfo["rec"] = $rng->GenerateString();
							$cinfo["rec_duration"] = (int)$_REQUEST["RecordingDuration"];

							file_put_contents($rootpath . "/files/record_" . $row->id . "_" . $cinfo["rec"] . ".mp3", $result["body"]);

							$db->Query("UPDATE", array("calls", array(
								"voicemail" => 1,
								"info" => json_encode($cinfo, JSON_UNESCAPED_SLASHES)
							), "WHERE" => "id = ?"), $row->id);

							// Delete the original recording.
							$twilio->RunAPI("DELETE", "Recordings/" . $_REQUEST["RecordingSid"]);

							// Send notifications.
							require_once $rootpath . "/support/smtp.php";

							$dispinfo = $pinfo["format_phone"] . ($pinfo["name"] !== "" ? " (" . $pinfo["name"] . ")" : "");
							foreach ($allowedphones as $phone2 => $pinfo)
							{
								$manageurl = Request::GetFullURLBase() . "manage.php?t1=" . urlencode($phone2) . "&t2=" . TwilioSDK::hash_hmac_internal("sha1", $phone2, $managesecret);

								if ($pinfo["notify"] === "all" || $pinfo["notify"] === "sms")
								{
									$postvars = array(
										"From" => $tcinfo["to"],
										"To" => $phone2,
										"Body" => "New voicemail at " . date("M j, Y, g:i a", $cinfo["ts"]) . " from " . $dispinfo
									);

									$twilio->RunAPI("POST", "Messages", $postvars);
								}

								if (($pinfo["notify"] === "all" || $pinfo["notify"] === "email") && isset($pinfo["email"]) && $pinfo["email"] !== "")
								{
									$message = $pinfo["name"] . ",\n\nA new voicemail is available from " . $dispinfo . ".\n\nTo process, call:  " . $tcinfo["to"] . "\n\nOr access the" . ($config["company_name"] != "" ? " " . $config["company_name"] : "") . " voicemail manager at:\n\n" . $manageurl;

									$smtpoptions = array(
										"headers" => SMTP::GetUserAgent("Thunderbird"),
										"textmessage" => $message,
									);

									$smtpoptions = array_merge($smtpoptions, $emailoptions);

									SMTP::SendEmail($emailfrom, $pinfo["email"], "[Voicemail Manager] New voicemail from " . $dispinfo, $smtpoptions);
								}
							}
						}
					}
				}
				catch (Exception $e)
				{
					echo "An internal database error occurred.";

					exit();
				}
			}
		}
	}
	else if (!isset($_REQUEST["From"]))
	{
		$twilio->OutputXMLTag("Reject", array());
	}
	else
	{
		// Load current caller info.
		$phone = preg_replace('/[^0-9]/', "", $_REQUEST["From"]);

		try
		{
			$row = $db->GetRow("SELECT", array(
				"*",
				"FROM" => "?",
				"WHERE" => "phone = ?"
			), "phones", $phone);

			if ($row !== false)
			{
				$info = LoadPhoneInfo(json_decode($row->info, true));

				if ($info["forward_until"] > 0 && $info["forward_until"] < time())
				{
					$info["forward_until"] = 0;
					$info["forward_to"] = "";
				}

				$todayts = time() - 23 * 60 * 60;

				$todaycalls = $db->GetOne("SELECT", array(
					"COUNT(*) as c",
					"FROM" => "?",
					"WHERE" => "pid = ? AND created > ?"
				), "calls", $row->id, $todayts);

				$todayrecs = $db->GetOne("SELECT", array(
					"COUNT(*) as c",
					"FROM" => "?",
					"WHERE" => "pid = ? AND status <> '' AND voicemail = 1 AND created > ?"
				), "calls", $row->id, $todayts);

				$crow = $db->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "pid = ? AND sid = ?"
				), "calls", $row->id, $_REQUEST["CallSid"]);
			}
			else
			{
				$info = LoadPhoneInfo(array());

				$info["phone"] = $_REQUEST["From"];

				$crow = false;
				$todaycalls = 0;
				$todayrecs = 0;
			}
		}
		catch (Exception $e)
		{
			$twilio->OutputXMLTag("Say", array(), "An internal database error occurred.  Goodbye.");

			$twilio->EndXMLResponse();

			exit();
		}

		// Ignore repeated call connect attempts by the carrier within a short period of time.
		if ($_REQUEST["CallStatus"] === "ringing" && $row !== false && $row->lastcalled > time() - 5)
		{
			$twilio->OutputXMLTag("Reject", array());

			$twilio->EndXMLResponse();

			exit();
		}

		// Initialize the call info.
		if ($crow !== false)  $cinfo = LoadCallInfo(json_decode($crow->info, true));
		else
		{
			$cinfo = LoadCallInfo(array());
			$cinfo["ts"] = time();

			foreach ($_REQUEST as $key => $val)
			{
				if (substr($key, 0, 6) === "Caller" || substr($key, 0, 4) === "From")  $cinfo["details"][$key] = $val;
			}
		}

		if ($info["name"] === "" && isset($cinfo["details"]["CallerName"]) && $cinfo["details"]["CallerName"] != "" && $cinfo["details"]["CallerName"] !== $cinfo["details"]["From"])  $info["name"] = $cinfo["details"]["CallerName"];
		if ($info["location"] === "" && isset($cinfo["details"]["CallerCity"]) && $cinfo["details"]["CallerCity"] != "")  $info["location"] = $cinfo["details"]["CallerCity"] . ", " . $cinfo["details"]["CallerState"] . " " . $cinfo["details"]["CallerZip"] . ", " . $cinfo["details"]["CallerCountry"];

		// If the manager has blocked the incoming phone number (e.g. toxic people), just drop the call.
		if ($info["status"] === "Blocked" && !isset($allowedphones[$_REQUEST["From"]]))
		{
			$twilio->OutputXMLTag("Reject", array());

			$cinfo["status"] = "Rejected";
		}
		else
		{
			if (isset($allowedphones[$_REQUEST["From"]]))
			{
				// Admin user.
				session_start();

				if (!isset($_SESSION["cancelled"]))  $_SESSION["cancelled"] = array();

				if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "assignments")
				{
					// Find the next assignment.
					if (isset($_REQUEST["id"]))
					{
						$prow = $db->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "id = ? AND assignedto = ?"
						), "phones", $_REQUEST["id"], $_REQUEST["From"]);

						if ($row === false)  unset($_REQUEST["id"]);
					}

					if (!isset($_REQUEST["id"]))
					{
						$prow = $db->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "assignedto = ?"
						), "phones", $_REQUEST["From"]);
					}

					if ($prow !== false)
					{
						$pinfo = LoadPhoneInfo(json_decode($prow->info, true));

						$redirected = false;

						// Call back.
						if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "1")
						{
							// Update the phone information.
							if ($pinfo["phone"] !== $_REQUEST["From"] && $pinfo["forward_until"] > -1)
							{
								$pinfo["forward_to"] = $_REQUEST["From"];
								$pinfo["forward_until"] = max($pinfo["forward_until"], time() + $config["callback_forward"] * 60);

								$db->Query("UPDATE", array("phones", array(
									"info" => json_encode($pinfo, JSON_UNESCAPED_SLASHES)
								), "WHERE" => "id = ?"), $prow->id);
							}

							$twilio->OutputXMLTag("Dial", array("timeout" => 60, "callerId" => $_REQUEST["To"]), $pinfo["phone"]);
							$twilio->OutputXMLTag("Say", array(), "Call back completed.");
						}

						// Replay voicemail.
						if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "2")
						{
							unset($_REQUEST["skipvoicemail"]);
						}

						if (isset($_REQUEST["Digits"]) && ($_REQUEST["Digits"] == "3" || $_REQUEST["Digits"] == "4" || $_REQUEST["Digits"] == "5"))
						{
							// Complete/cancel assignment.
							if ($_REQUEST["Digits"] == "3")  $mode = "complete";
							else if ($_REQUEST["Digits"] == "4")  $mode = "cancel";
							else if ($_REQUEST["Digits"] == "5")  $mode = "cancel_mark";

							if ($mode === "complete" && $pinfo["phone"] !== $_REQUEST["From"] && $pinfo["forward_until"] > -1)
							{
								$pinfo["forward_to"] = $_REQUEST["From"];
								$pinfo["forward_until"] = time() + $config["completed_forward"] * 60;
							}

							if ($mode === "cancel")  $_SESSION["cancelled"][$prow->id] = true;

							$pinfo["assigned_to"] = "";

							$db->BeginTransaction();

							$db->Query("UPDATE", array("phones", array(
								"assignedto" => $pinfo["assigned_to"],
								"info" => json_encode($pinfo, JSON_UNESCAPED_SLASHES)
							), "WHERE" => "id = ?"), $prow->id);

							if ($mode === "complete" || $mode === "cancel_mark")
							{
								$result = $db->Query("SELECT", array(
									"*",
									"FROM" => "?",
									"WHERE" => "pid = ? AND status = 'New'"
								), "calls", $prow->id);

								while ($row2 = $result->NextRow())
								{
									$cinfo2 = LoadCallInfo(json_decode($row2->info, true));

									$cinfo2["status"] = "Handled";

									$db->Query("UPDATE", array("calls", array(
										"status" => $cinfo2["status"],
										"info" => json_encode($cinfo2, JSON_UNESCAPED_SLASHES)
									), "WHERE" => "id = ?"), $row2->id);
								}
							}

							$db->Commit();

							if ($mode === "complete")  $twilio->OutputXMLTag("Say", array(), "Assignment completed.");
							else if ($mode === "cancel")  $twilio->OutputXMLTag("Say", array(), "Assignment cancelled.");
							else if ($mode === "cancel_mark")  $twilio->OutputXMLTag("Say", array(), "Assignment cancelled and voicemails marked as handled.");

							$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase() . "?action=assignments");
						}
						else if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "6")
						{
							// Main menu.
							$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase());
						}
						else
						{
							$twilio->OpenXMLTag("Gather", array("numDigits" => "1", "action" => Request::GetFullURLBase() . "?action=assignments&id=" . $prow->id . "&skipvoicemail=1"));

							if (!isset($_REQUEST["skipvoicemail"]))
							{
								$num = 0;

								$result = $db->Query("SELECT", array(
									"*",
									"FROM" => "?",
									"WHERE" => "pid = ? AND status = 'New' AND voicemail = 1",
									"ORDER BY" => "created"
								), "calls", $prow->id);

								while ($row2 = $result->NextRow())
								{
									$cinfo2 = LoadCallInfo(json_decode($row2->info, true));

									$twilio->OutputXMLTag("Play", array(), str_replace("/index.php", "/", Request::GetFullURLBase()) . "files/record_" . $row2->id . "_" . $cinfo2["rec"] . ".mp3");

									$num++;
								}

								// Say the phone number if no voicemails were found.
								if (!$num)  $twilio->OutputXMLTag("Say", array(), "No voicemails found for " . SpeechReadyPhone($pinfo["phone"]) . ".");
							}

							$options = array(
								"Press 1 to call back.",
								"Press 2 to replay voicemail.",
								"Press 3 to complete assignment.",
								"Press 4 to cancel assignment.",
								"Press 5 to cancel assignment and mark voicemail as handled.",
								"Press 6 to return to the main menu."
							);

							$twilio->OutputXMLTag("Say", array(), implode("  ", $options));
							$twilio->CloseXMLTag("Gather");
							$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase() . "?action=assignments&id=" . $prow->id . "&skipvoicemail=1");
						}
					}
					else
					{
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase());
					}
				}
				else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "processvoicemail")
				{
					// Locate the the first unassigned voicemail in the queue and self-assign the associated phone.
					$result = $db->Query("SELECT", array(
						"c.*, p.info AS pinfo",
						"FROM" => "? AS p, ? AS c",
						"WHERE" => "p.id = c.pid AND p.assignedto = '' AND c.status = 'New' AND c.voicemail = 1",
						"ORDER BY" => "c.created"
					), "phones", "calls");

					$readyid = false;

					while ($readyid === false && ($row2 = $result->NextRow()))
					{
						if (isset($_SESSION["cancelled"][$row2->pid]))  continue;

						$pinfo = LoadPhoneInfo(json_decode($row2->pinfo, true));

						$pinfo["assigned_to"] = $_REQUEST["From"];

						$db->Query("UPDATE", array("phones", array(
							"assignedto" => $pinfo["assigned_to"],
							"info" => json_encode($pinfo, JSON_UNESCAPED_SLASHES)
						), "WHERE" => "id = ? AND assignedto = ''"), $row2->pid);

						$readyid = $db->GetOne("SELECT", array(
							"id",
							"FROM" => "?",
							"WHERE" => "id = ? AND assignedto = ?"
						), "phones", $row2->pid, $_REQUEST["From"]);
					}

					if ($readyid !== false)  $twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase() . "?action=assignments&id=" . $readyid);
					else
					{
						$twilio->OutputXMLTag("Say", array(), "No unassigned voicemails available.");
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase());
					}
				}
				else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "makecall")
				{
					// Make a call.
					if (isset($_REQUEST["Digits"]) && strlen($_REQUEST["Digits"]) >= 7)
					{
						$twilio->OutputXMLTag("Dial", array("timeout" => 60, "callerId" => $_REQUEST["To"]), "+" . $_REQUEST["Digits"]);
						$twilio->OutputXMLTag("Say", array(), "Call completed.");
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase());
					}
					else
					{
						if (isset($_REQUEST["Digits"]))  $twilio->OutputXMLTag("Say", array(), "Invalid or incomplete phone number.  Try again.");

						$twilio->OpenXMLTag("Gather", array("numDigits" => "15"));
						$twilio->OutputXMLTag("Say", array(), "Enter the full phone number to call and press # .");
						$twilio->CloseXMLTag("Gather");
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase());
					}
				}
				else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "public")
				{
					// Listen to the configured XMLs.
					if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "1")  echo $config["start_call"];
					else if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "2")  echo $config["voicemail_route"];
					else if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "3")  echo $config["too_many_calls"];
					else if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "4")  $twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase());

					if (isset($_REQUEST["Digits"]))  $twilio->OutputXMLTag("Pause");

					$options = array(
						"Press 1 to listen to the start of every public call.",
						"Press 2 to listen to the voicemail route.",
						"Press 3 to listen to the too many calls message.",
						"Press 4 or wait to return to the main menu.",
					);

					$twilio->OpenXMLTag("Gather", array("numDigits" => "1"));
					$twilio->OutputXMLTag("Say", array(), implode("  ", $options));
					$twilio->CloseXMLTag("Gather");
					$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase());
				}
				else
				{
					// Handle selections for the main menu.
					if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "1")
					{
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase() . "?action=assignments");
					}
					else if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "2")
					{
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase() . "?action=processvoicemail");
					}
					else if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "3")
					{
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase() . "?action=makecall");
					}
					else if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "4")
					{
						// Send a SMS.
						$manageurl = Request::GetFullURLBase() . "manage.php?t1=" . urlencode($_REQUEST["From"]) . "&t2=" . TwilioSDK::hash_hmac_internal("sha1", $_REQUEST["From"], $managesecret);

						$postvars = array(
							"From" => $_REQUEST["To"],
							"To" => $_REQUEST["From"],
							"Body" => "Manage:  " . $manageurl
						);

						$twilio->RunAPI("POST", "Messages", $postvars);

						// Send an email.
						if (isset($allowedphones[$_REQUEST["From"]]["email"]) && $allowedphones[$_REQUEST["From"]]["email"] != "")
						{
							require_once $rootpath . "/support/smtp.php";

							$message = $allowedphones[$_REQUEST["From"]]["name"] . ",\n\nAccess the voicemail manager at:\n\n" . $manageurl;

							$smtpoptions = array(
								"headers" => SMTP::GetUserAgent("Thunderbird"),
								"textmessage" => $message,
							);

							$smtpoptions = array_merge($smtpoptions, $emailoptions);

							SMTP::SendEmail($emailfrom, $allowedphones[$_REQUEST["From"]]["email"], "[Voicemail Manager] Access requested", $smtpoptions);
						}

						$twilio->OutputXMLTag("Say", array(), "Sent access message.  Goodbye.");

						$cinfo["status"] = "Sent Message";
					}
					else if (isset($_REQUEST["Digits"]) && $_REQUEST["Digits"] == "5")
					{
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase() . "?action=public");
					}
					else
					{
						// Main menu.
						$options = array();
						$options[] = trim($config["company_name"] . " Voicemail Manager main menu.");

						$num = $db->GetOne("SELECT", array(
							"COUNT(DISTINCT c.pid)",
							"FROM" => "? AS p, ? AS c",
							"WHERE" => "p.id = c.pid AND p.assignedto = ? AND c.created > ?",
							"GROUP BY" => "c.pid"
						), "phones", "calls", $_REQUEST["From"], time() - 7 * 24 * 60 * 60);

						if ($num > 1)  $options[] = "Press 1 to process " . $num . " assignments.";
						else if ($num == 1)  $options[] = "Press 1 to process one assignment.";

						$num = $db->GetOne("SELECT", array(
							"COUNT(DISTINCT c.pid)",
							"FROM" => "? AS p, ? AS c",
							"WHERE" => "p.id = c.pid AND p.assignedto = '' AND c.status = 'New' AND c.voicemail = 1",
							"GROUP BY" => "c.pid"
						), "phones", "calls");

						if ($num > 1)  $options[] = "Press 2 to process the next voicemail of " . $num . " available.";
						else if ($num)  $options[] = "Press 2 to process the next voicemail.";

						$options[] = "Press 3 to place a call.";
						$options[] = "Press 4 to access voicemail in your web browser.";
						$options[] = "Press 5 to listen to what the public hears.";

						$twilio->OpenXMLTag("Gather", array("numDigits" => "1"));
						$twilio->OutputXMLTag("Say", array(), implode("  ", $options));
						$twilio->CloseXMLTag("Gather");
						$twilio->OutputXMLTag("Redirect", array(), Request::GetFullURLBase());

						$cinfo["status"] = "Handled";
					}
				}
			}
			else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "forward")
			{
				// Handle forwarding attempts.
				if ($_REQUEST["DialCallStatus"] !== "no-answer")
				{
					if ($_REQUEST["DialCallStatus"] === "completed" || $_REQUEST["DialCallStatus"] === "answered")  $cinfo["status"] = "Handled";
				}
				else if (isset($_REQUEST["attempts"]) && (int)$_REQUEST["attempts"] < 2)
				{
					// Dial up to two times.  Cut short before the user's voicemail would kick in.
					$twilio->OutputXMLTag("Dial", array("timeout" => 20, "action" => Request::GetFullURLBase() . "?action=forward&attempts=" . ((int)$_REQUEST["attempts"] + 1), "callerId" => $_REQUEST["To"]), $info["forward_to"]);
				}
				else
				{
					// Fallback to the voicemail route.
					echo $config["voicemail_route"];

					$twilio->OutputXMLTag("Record", array("maxLength" => "120", "playBeep" => "true", "finishOnKey" => "#", "recordingStatusCallback" => Request::GetFullURLBase()));
				}
			}
			else if ($info["forward_until"] != 0 && $info["forward_to"] !== "")
			{
				// Dial the destination number.
				echo $config["start_call"];

				// Dial up to two times.  Cut short before the user's voicemail would kick in.
				$twilio->OutputXMLTag("Dial", array("timeout" => 20, "action" => Request::GetFullURLBase() . "?action=forward&attempts=1", "callerId" => $_REQUEST["To"]), $info["forward_to"]);
			}
			else if ($todaycalls > $config["max_calls_per_day"] || $todayrecs > $config["max_recordings_per_day"])
			{
				$twilio->OutputXMLTag("Reject", array());

				$cinfo["status"] = "Rejected";
			}
			else if ($todaycalls == $config["max_calls_per_day"] || $todayrecs == $config["max_recordings_per_day"])
			{
				echo $config["too_many_calls"];
			}
			else
			{
				// Voicemail only route.
				echo $config["start_call"];
				echo $config["voicemail_route"];

				$twilio->OutputXMLTag("Record", array("maxLength" => "120", "playBeep" => "true", "finishOnKey" => "#", "recordingStatusCallback" => Request::GetFullURLBase()));
			}
		}

		// Save the information to the database.
		try
		{
			if ($row === false)
			{
				$db->Query("INSERT", array("phones", array(
					"phone" => $phone,
					"assignedto" => "",
					"status" => $info["status"],
					"lastcalled" => time(),
					"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
				), "AUTO INCREMENT" => "id"));

				$id = $db->GetInsertID();
			}
			else
			{
				$db->Query("UPDATE", array("phones", array(
					"lastcalled" => time(),
					"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
				), "WHERE" => "id = ?"), $row->id);

				$id = $row->id;
			}

			if ($crow === false)
			{
				$db->Query("INSERT", array("calls", array(
					"pid" => $id,
					"sid" => $_REQUEST["CallSid"],
					"created" => $cinfo["ts"],
					"status" => $cinfo["status"],
					"voicemail" => 0,
					"info" => json_encode($cinfo, JSON_UNESCAPED_SLASHES)
				)));
			}
			else
			{
				$db->Query("UPDATE", array("calls", array(
					"info" => json_encode($cinfo, JSON_UNESCAPED_SLASHES)
				), "WHERE" => "id = ?"), $crow->id);
			}
		}
		catch (Exception $e)
		{
			echo "An internal database error occurred.";

			exit();
		}
	}

	$twilio->EndXMLResponse();
?>