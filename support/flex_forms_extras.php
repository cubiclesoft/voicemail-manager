<?php
	// FlexForms server-side extras.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	class FlexFormsExtras
	{
		// Date fields.
		public static function DateFieldType(&$state, $num, &$field, $id)
		{
			if ($field["type"] === "date")
			{
				if (!isset($field["width"]))  $field["width"] = "20em";

?>
			<div class="formitemdata">
				<div class="textitemwrap"<?php if (isset($field["width"]))  echo " style=\"" . ($state["responsive"] ? "max-" : "") . "width: " . htmlspecialchars($field["width"]) . "\""; ?>><input class="date" type="text" id="<?php echo htmlspecialchars($id); ?>" name="<?php echo htmlspecialchars($field["name"]); ?>" value="<?php echo htmlspecialchars($field["value"]); ?>" /></div>
			</div>
<?php
				$state["jqueryuiused"] = true;

				$options = array("dateFormat" => "yy-mm-dd");

				// Allow the datepicker to be fully customized beyond basic support.
				// Valid options:  http://api.jqueryui.com/datepicker/
				if (isset($field["options"]))
				{
					foreach ($field["options"] as $key => $val)  $options[$key] = $val;
				}

				// Queue up the necessary Javascript for later output.
				ob_start();
?>
	jQuery(function() {
		var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
<?php
				if (isset($field["callbacks"]))
				{
					foreach ($field["callbacks"] as $key => $val)
					{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
					}
				}
?>

		if (jQuery.fn.datepicker)
		{
			if (jQuery(window).width() < jQuery(window).height() || jQuery(window).height() < 600)  jQuery('#<?php echo FlexForms::JSSafe($id); ?>').prop('readonly', true);

			jQuery('#<?php echo FlexForms::JSSafe($id); ?>').attr("autocomplete", "off").datepicker(options);
		}
		else
		{
			alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing jQuery UI for date field.\n\nThis feature requires FlexForms Extras.")); ?>');
		}
	});
<?php

				$state["js"]["date|" . $id] = array("mode" => "inline", "dependency" => "jqueryui", "src" => ob_get_contents());
				ob_end_clean();
			}
		}

		// Generate a UNIX timestamp for a user-submitted date and time.  International input format is best.
		public static function ParseDateTime($date, $time)
		{
			if ($date === "" || $time === "")  return false;

			$year = false;
			$month = false;
			$day = false;

			// Extract named month.
			$monthmap = array(
				"jan" => 1, "feb" => 2, "mar" => 3, "apr" => 4, "may" => 5, "jun" => 6,
				"jul" => 7, "aug" => 8, "sep" => 9, "oct" => 10, "nov" => 11, "dec" => 12
			);
			foreach ($monthmap as $key => $val)
			{
				$key = FlexForms::FFTranslate($key);

				if (stripos($date, $key) !== false)
				{
					$month = $val;

					break;
				}
			}

			$items = explode(" ", preg_replace('/\s+/', " ", preg_replace('/[^0-9]/', " ", $date)));

			foreach ($items as $item)
			{
				if (strlen($item) >= 4)  $year = (int)$item;
				else if ($month === false)  $month = (int)$item;
				else
				{
					$day = (int)$item;

					break;
				}
			}

			$hour = false;
			$min = false;
			$sec = false;
			$ampm = false;

			if (stripos($time, "a") !== false)  $ampm = "a";
			else if (stripos($time, "p") !== false)  $ampm = "p";

			$items = explode(" ", preg_replace('/\s+/', " ", preg_replace('/[^0-9]/', " ", $time)));

			foreach ($items as $item)
			{
				if ($hour === false)  $hour = (int)$item;
				else if ($min === false)  $min = (int)$item;
				else
				{
					$sec = (int)$item;

					break;
				}
			}

			if ($hour !== false && $ampm !== false)
			{
				if ($ampm === "a" && ($hour < 1 || $hour == 12))  $hour = 0;
				else if ($ampm === "p" && $hour > 0 && $hour < 12)  $hour += 12;
			}

			if ($min === false)  $min = 0;
			if ($sec === false)  $sec = 0;

			if ($year === false || $year === 0 || $month === false || $day === false || $hour === false)  return false;

			$ts = @mktime($hour, $min, $sec, $month, $day, $year);
			if ($ts < 0)  return false;

			return $ts;
		}

		public static function ParseDate($date)
		{
			return self::ParseDateTime($date, "0");
		}


		// Accordions.
		public static function AccordionsInit(&$state, &$options)
		{
			$state["extras_accordion"] = false;
			$state["extras_insideaccordion"] = false;

			$state["customfieldtypes"]["accordion"] = true;
			$state["customfieldtypes"]["accordian"] = true;
		}

		public static function AccordionsFieldString(&$state, $num, &$field, $id)
		{
			if (($field == "endaccordion" || $field == "endaccordian") && $state["extras_insideaccordion"])
			{
				if ($state["insiderow"])
				{
?>
			</tr></table></div>
<?php
					$state["insiderow"] = false;
				}
?>
				</div>
			</div>
<?php
				$state["extras_insideaccordion"] = false;
			}
			else if ($field == "nosplit")
			{
				if ($state["extras_insideaccordion"])  $state["firstitem"] = true;
			}
		}

		public static function AccordionsCustomFieldType(&$state, $num, &$field, $id)
		{
			if ($field["type"] == "accordion" || $field["type"] == "accordian")
			{
				if ($state["insiderow"])
				{
?>
			</tr></table></div>
<?php
					$state["insiderow"] = false;
				}

				if ($state["extras_insideaccordion"])
				{
?>
				</div>
				<h3><?php echo htmlspecialchars(FlexForms::FFTranslate($field["title"])); ?></h3>
				<div class="formaccordionitems">
<?php
				}
				else
				{
					$id2 = $id . "_accordion";
?>
			<div class="formaccordionwrap" id="<?php echo htmlspecialchars($id2); ?>">
				<h3><?php echo htmlspecialchars(FlexForms::FFTranslate($field["title"])); ?></h3>
				<div class="formaccordionitems">
<?php
					$state["extras_insideaccordion"] = true;

					if ($state["extras_accordion"] === false)
					{
						$state["jqueryuiused"] = true;

						ob_start();
?>
FlexForms.modules.Extras_Accordion_Activate = function(event, ui) {
	jQuery(window).trigger('child:visibility');
}
<?php
						$state["js"]["accordion-activate"] = array("mode" => "inline", "dependency" => "jqueryui", "src" => ob_get_contents(), "detect" => "FlexForms.modules.Extras_Accordion_Activate");
						ob_end_clean();

						$state["extras_accordion"] = true;
					}

					$options = array(
						"collapsible" => true,
						"active" => false,
						"heightStyle" => "content"
					);

					// Allow each accordion to be fully customized beyond basic support.
					// Valid options:  http://api.jqueryui.com/accordion/
					if (isset($field["options"]))
					{
						foreach ($field["options"] as $key => $val)  $options[$key] = $val;
					}

					// Queue up the necessary Javascript for later output.
					ob_start();
?>
	jQuery(function() {
		var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
<?php
				if (!isset($field["callbacks"]))  $field["callbacks"] = array();

				$field["callbacks"]["activate"] = "FlexForms.modules.Extras_Accordion_Activate";

				foreach ($field["callbacks"] as $key => $val)
				{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
				}
?>

		if (jQuery.fn.accordion)  jQuery('#<?php echo FlexForms::JSSafe($id2); ?>').accordion(options);
		else  alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing jQuery UI for accordion.\n\nThis feature requires FlexForms Extras.")); ?>');
	});
<?php

					$state["js"]["accordion|" . $id2] = array("mode" => "inline", "dependency" => "accordion-activate", "src" => ob_get_contents());
					ob_end_clean();
				}

				$state["firstitem"] = true;
			}
		}

		public static function AccordionsCleanup(&$state)
		{
			if ($state["extras_insideaccordion"])
			{
?>
				</div>
			</div>
<?php

				$state["extras_insideaccordion"] = false;
			}
		}


		// Multiselect.
		public static function MultiselectInit(&$state, &$options)
		{
			if (!isset($state["extras_multiselect_tags"]))
			{
				$state["extras_multiselect_tags"] = false;
				$state["extras_multiselect_dropdown"] = false;
				$state["extras_multiselect_flat"] = false;
			}
		}

		public static function MultiselectFieldType(&$state, $num, &$field, $id)
		{
			if ($field["type"] == "select" && (isset($field["multiple"]) && $field["multiple"] === true) && isset($field["mode"]))
			{
				if ($field["mode"] == "tags")
				{
					if ($state["extras_multiselect_tags"] === false)
					{
						$state["css"]["multiselect-select2"] = array("mode" => "link", "dependency" => false, "src" => $state["supporturl"] . "/multiselect-select2/select2.css");
						$state["js"]["multiselect-select2"] = array("mode" => "src", "dependency" => "jquery", "src" => $state["supporturl"] . "/multiselect-select2/select2.min.js", "detect" => "jQuery.fn.select2");

						$state["extras_multiselect_tags"] = true;
					}

					$options = array("__flexforms" => true);
					if (isset($field["mininput"]))  $options["minimumInputLength"] = (int)$field["mininput"];

					// Allow each select2 instance to be fully customized beyond basic support.
					// Valid options:  https://select2.github.io/options.html
					if (isset($field["multiselect_options"]))
					{
						foreach ($field["multiselect_options"] as $key => $val)  $options[$key] = $val;
					}

					// Queue up the necessary Javascript for later output.
					ob_start();
?>
	jQuery(function() {
		var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
<?php
					if (isset($field["multiselect_callbacks"]))
					{
						foreach ($field["multiselect_callbacks"] as $key => $val)
						{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
						}
					}
?>

		if (jQuery.fn.select2)
		{
			jQuery('#<?php echo FlexForms::JSSafe($id); ?>').select2(options);

			if (jQuery(window).width() < jQuery(window).height() || jQuery(window).height() < 600)  jQuery('#<?php echo FlexForms::JSSafe($id); ?>').closest('.selectitemwrap').find('.select2-input').prop('readonly', true);
		}
		else
		{
			alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing select2 for multiple selection field.\n\nThis feature requires FlexForms Extras.")); ?>');
		}
	});
<?php
					$state["js"]["multiselect-select2|" . $id] = array("mode" => "inline", "dependency" => "multiselect-select2", "src" => ob_get_contents());
					ob_end_clean();

					$field["mode"] = "formhandler";
				}
				else if ($field["mode"] == "dropdown" && $state["extras_multiselect_flat"] === false)
				{
					if ($state["extras_multiselect_dropdown"] === false)
					{
						$state["jqueryuiused"] = true;

						$state["css"]["multiselect-widget"] = array("mode" => "link", "dependency" => "jqueryui", "src" => $state["supporturl"] . "/multiselect-widget/jquery.multiselect.css");
						$state["css"]["multiselect-widget-filter"] = array("mode" => "link", "dependency" => "multiselect-widget", "src" => $state["supporturl"] . "/multiselect-widget/jquery.multiselect.filter.css");
						$state["js"]["multiselect-widget"] = array("mode" => "src", "dependency" => "jqueryui", "src" => $state["supporturl"] . "/multiselect-widget/jquery.multiselect.min.js", "detect" => "jQuery.fn.multiselect");
						$state["js"]["multiselect-widget-filter"] = array("mode" => "src", "dependency" => "multiselect-widget", "src" => $state["supporturl"] . "/multiselect-widget/jquery.multiselect.filter.js", "detect" => "jQuery.fn.multiselectfilter");

						$state["extras_multiselect_dropdown"] = true;
					}

					$options = array(
						"selectedText" => FlexForms::FFTranslate("# of # selected"),
						"selectedList" => 5,
						"height" => (isset($field["height"]) ? (int)$field["height"] : 200),
						"position" => array(
							"my" => "left top",
							"at" => "left bottom",
							"collision" => "flip"
						)
					);

					// Allow each multiselect instance to be fully customized beyond basic support.
					// Valid options:  http://www.erichynds.com/blog/jquery-ui-multiselect-widget
					if (isset($field["multiselect_options"]))
					{
						foreach ($field["multiselect_options"] as $key => $val)  $options[$key] = $val;
					}

					// Queue up the necessary Javascript for later output.
					ob_start();
?>
	jQuery(function() {
		var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
<?php
					if (isset($field["multiselect_callbacks"]))
					{
						foreach ($field["multiselect_callbacks"] as $key => $val)
						{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
						}
					}
?>

		if (jQuery.fn.multiselect && jQuery.fn.multiselectfilter)
		{
			var scrollarea;

			jQuery('#<?php echo FlexForms::JSSafe($id); ?>').parents().each(function() {
				var innerheight = jQuery(this).innerHeight();

				if (!scrollarea && innerheight > 0 && this.scrollHeight - 5 > innerheight)  scrollarea = this;
			});

			jQuery('#<?php echo FlexForms::JSSafe($id); ?>').multiselect(options).multiselectfilter();

			var resizefunc = function() {
				var obj = jQuery('#<?php echo FlexForms::JSSafe($id); ?>');

				if (!obj.length)
				{
					jQuery(window).off('resize', resizefunc);
					jQuery(scrollarea).off('scroll', scrollfunc);
				}
				else
				{
					obj.multiselect('close').multiselect('refresh');
				}
			};

			var scrollfunc = function() {
				var obj = jQuery('#<?php echo FlexForms::JSSafe($id); ?>');

				if (obj.length && obj.multiselect('isOpen'))  obj.multiselect('close');
			};

			jQuery(window).resize(resizefunc);
			jQuery(scrollarea).scroll(scrollfunc);
		}
		else
		{
			alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing jQuery UI multiselect widget or multiselectfilter for dropdown multiple selection field.\n\nThis feature requires FlexForms Extras.")); ?>');
		}
	});
<?php
					$state["js"]["multiselect-widget-filter|" . $id] = array("mode" => "inline", "dependency" => "multiselect-widget-filter", "src" => ob_get_contents());
					ob_end_clean();

					$field["mode"] = "formhandler";
				}
				else if ($field["mode"] == "flat" && $state["extras_multiselect_dropdown"] === false)
				{
					if ($state["extras_multiselect_flat"] === false)
					{
						$state["jqueryuiused"] = true;

						$state["css"]["multiselect-flat"] = array("mode" => "link", "dependency" => "jqueryui", "src" => $state["supporturl"] . "/multiselect-flat/css/jquery.uix.multiselect.css");
						$state["js"]["multiselect-flat"] = array("mode" => "src", "dependency" => "jqueryui", "src" => $state["supporturl"] . "/multiselect-flat/js/jquery.uix.multiselect.min.js", "detect" => "jQuery.fn.multiselect");

						$state["extras_multiselect_flat"] = true;
					}

					// Reorder selected options so that they appear in the correct order.
					if (isset($field["select"]))
					{
						if (is_string($field["select"]))  $field["select"] = array($field["select"] => true);

						$selected = $field["select"];
						foreach ($field["options"] as $name => $value)
						{
							if (is_array($value))
							{
								foreach ($value as $name2 => $value2)
								{
									if (isset($selected[$name2]))
									{
										$selected[$name2] = $value2;

										unset($value[$name2]);
									}
								}

								$field["options"][$name] = $value;
							}
							else if (isset($selected[$name]))
							{
								$selected[$name] = $value;

								unset($field["options"][$name]);
							}
						}

						$options = array();
						foreach ($selected as $name => $value)
						{
							if (is_string($value))  $options[$name] = $value;
						}
						foreach ($field["options"] as $name => $value)
						{
							$options[$name] = $value;
						}

						$field["options"] = $options;
					}

					$options = array(
						"availableListPosition" => ($state["formtables"] ? "left" : "top"),
						"sortable" => true,
						"sortMethod" => NULL
					);

					// Allow each multiselect instance to be fully customized beyond basic support.
					// Valid options:  https://github.com/yanickrochon/jquery.uix.multiselect/wiki/API-Documentation
					if (isset($field["multiselect_options"]))
					{
						foreach ($field["multiselect_options"] as $key => $val)  $options[$key] = $val;
					}

					// Queue up the necessary Javascript for later output.
					ob_start();
?>
	jQuery(function() {
		if (jQuery.fn.multiselect)
		{
			var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
<?php
				if (isset($field["multiselect_callbacks"]))
				{
					foreach ($field["multiselect_callbacks"] as $key => $val)
					{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
					}
				}
?>

			var obj = jQuery('#<?php echo FlexForms::JSSafe($id); ?>');
			var origheight = obj.height();

			obj.closest('.selectitemwrap').after('<div style="clear: both;"></div>');

<?php
				if ($state["formtables"] && $state["responsive"])
				{
					if (!isset($field["flatwidth"]) || !is_int($field["flatwidth"]))  $field["flatwidth"] = 600;

?>
			options['availableListPosition'] = (obj.width() > <?php echo $field["flatwidth"]; ?> ? 'left' : 'top');
			obj.height(options['availableListPosition'] === 'left' ? origheight : origheight * 2);
<?php
				}
?>

			obj.multiselect(options);

			var resizefunc = function() {
				var obj = jQuery('#<?php echo FlexForms::JSSafe($id); ?>');

				if (!obj.length)  jQuery(window).off('resize', resizefunc).off('child:visibility', resizefunc);
				else
				{
					obj.closest('.selectitemwrap').find('.uix-multiselect').width('100%');

<?php
				if ($state["formtables"] && $state["responsive"])
				{
?>
					var newpos = (obj.width() > <?php echo $field["flatwidth"]; ?> ? 'left' : 'top');
					if (newpos !== options['availableListPosition'])
					{
						obj.multiselect('destroy');

						obj.height(newpos === 'left' ? origheight : origheight * 2);
						options['availableListPosition'] = newpos;
						obj.multiselect(options);
					}
					else
					{
						obj.multiselect('refresh');
					}
<?php
				}
				else
				{
?>
					obj.multiselect('refresh');
<?php
				}
?>
				}
			};

			jQuery(window).resize(resizefunc).on('child:visibility', resizefunc);
		}
		else
		{
			alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing jQuery UI multiselect plugin for flat multiple selection field.\n\nThis feature requires FlexForms Extras.")); ?>');
		}
	});
<?php
					$state["js"]["multiselect-flat|" . $id] = array("mode" => "inline", "dependency" => "multiselect-flat", "src" => ob_get_contents());
					ob_end_clean();

					$field["mode"] = "formhandler";
				}
			}
		}


		// Responsive table cards for data tables.
		public static function TableCardsInit(&$state, &$options)
		{
			if (!isset($state["extras_table_cards"]))  $state["extras_table_cards"] = false;
		}

		public static function TableCardsFieldType(&$state, $num, &$field, $id)
		{
			if ($field["type"] == "table" && isset($field["card"]))
			{
				if ($state["extras_table_cards"] === false)
				{
					$state["css"]["table-cards"] = array("mode" => "link", "dependency" => false, "src" => $state["supporturl"] . "/jquery.tablecards.css");
					$state["js"]["table-cards"] = array("mode" => "src", "dependency" => "jquery", "src" => $state["supporturl"] . "/jquery.tablecards.js", "detect" => "jQuery.fn.TableCards");

					$state["extras_table_cards"] = true;
				}

				// Allow each TableCards instance to be fully customized beyond basic support.
				// Valid options:  See 'jquery.tablecards.js' file.
				$options = (is_array($field["card"]) ? $field["card"] : array("body" => $field["card"]));

				if (isset($field["cardhead"]))  $options["head"] = $field["cardhead"];
				if (isset($field["cardwidth"]))  $options["width"] = (int)$field["cardwidth"];
				if (isset($options["head"]) && !isset($options["extracols"]))  $options["extracols"] = array("headcol");

				if (isset($field["card_options"]))
				{
					foreach ($field["card_options"] as $key => $val)  $options[$key] = $val;
				}

				// Queue up the necessary Javascript for later output.
				ob_start();
?>
	jQuery(function() {
		var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;

<?php
				if (isset($field["card_callbacks"]))
				{
					foreach ($field["card_callbacks"] as $key => $val)
					{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
					}
				}
?>

		jQuery('#<?php echo FlexForms::JSSafe($id . "_table"); ?>').TableCards(options).on('tablecards:mode', function() {
			jQuery(this).trigger('table:columnschanged');
		}).on('table:datachanged', function() {
			jQuery(this).trigger('tablecards:datachanged');
		}).on('table:resized', function() {
			jQuery(this).trigger('tablecards:resize');
		});

		var resizefunc = function() {
			var obj = jQuery('#<?php echo FlexForms::JSSafe($id . "_table"); ?>');

			if (!obj.length)  jQuery(window).off('resize', resizefunc).off('child:visibility', resizefunc);
			else  obj.trigger('tablecards:resize');
		};

		jQuery(window).resize(resizefunc).on('child:visibility', resizefunc);
	});
<?php
				$state["js"]["table-cards|" . $id] = array("mode" => "inline", "dependency" => "table-cards", "src" => ob_get_contents());
				ob_end_clean();
			}
		}


		// Drag-and-drop reordering support for data tables.
		public static function TableOrderInit(&$state, &$options)
		{
			if (!isset($state["extras_table_order"]))  $state["extras_table_order"] = false;
		}

		public static function TableOrderTableRow(&$state, $num, &$field, $idbase, $type, $rownum, &$trattrs, &$colattrs, &$row)
		{
			if ($state["formtables"] && isset($field["order"]) && $field["order"] != "")
			{
				if ($type == "head")
				{
					$trattrs["id"] = $idbase . "_head";
					$trattrs["class"] .= " nodrag nodrop";
					array_unshift($row, FlexForms::FFTranslate($field["order"]));
					array_unshift($colattrs, array());

					if ($state["extras_table_order"] === false)
					{
						$state["js"]["table-order"] = array("mode" => "src", "dependency" => "jquery", "src" => $state["supporturl"] . "/jquery.tablednd.min.js", "detect" => "jQuery.fn.tableDnD");

						ob_start();
?>
FlexForms.modules.Extras_TableDnD_DefaultDrop = function(table, row) {
	var altrow = false;

	jQuery(table).find('tr.row').each(function(x) {
		if (altrow)  jQuery(this).addClass('altrow');
		else  jQuery(this).removeClass('altrow');

		altrow = !altrow;
	});
}
<?php
						$state["js"]["table-order-defaultdrop"] = array("mode" => "inline", "dependency" => "table-order", "src" => ob_get_contents(), "detect" => "FlexForms.modules.Extras_TableDnD_DefaultDrop");
						ob_end_clean();

						$state["extras_table_order"] = true;
					}

					$options = array(
						"dragHandle" => ".draghandle",
						"onDragClass" => "dragactive",
					);

					// Allow each tableDnD instance to be fully customized beyond basic support.
					// Valid options:  https://github.com/isocra/TableDnD
					if (isset($field["order_options"]))
					{
						foreach ($field["order_options"] as $key => $val)  $options[$key] = $val;
					}

					// Queue up the necessary Javascript for later output.
					ob_start();
?>
(function() {
	if (jQuery.fn.tableDnD)
	{
		var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
<?php
				if (!isset($field["order_callbacks"]))  $field["order_callbacks"] = array();

				// Legacy support for 'reordercallback'.
				if (isset($field["reordercallback"]))  $field["order_callbacks"]["onDrop"] = "function(table, row) { FlexForms.modules.Extras_TableDnD_DefaultDrop(table, row);  " .$field["reordercallback"]  . "(); }";
				else  $field["order_callbacks"]["onDrop"] = "FlexForms.modules.Extras_TableDnD_DefaultDrop";

				foreach ($field["order_callbacks"] as $key => $val)
				{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
				}
?>

		jQuery('#<?php echo FlexForms::JSSafe($idbase); ?>').on('table:datachanged', function() {
			jQuery(this).tableDnDUpdate();
		}).tableDnD(options);
	}
	else
	{
		alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing jQuery TableDnD plugin for drag-and-drop row ordering.\n\nThis feature requires FlexForms Extras.")); ?>');
	}
})();
<?php
					$state["js"]["table-order|" . $idbase] = array("mode" => "inline", "dependency" => "table-order-defaultdrop", "src" => ob_get_contents());
					ob_end_clean();
				}
				else if ($type == "body")
				{
					$trattrs["id"] = $idbase . "_" . $rownum;
					$colattrs[0]["class"] = "draghandle";
					array_unshift($row, "&nbsp;");
				}
			}
		}


		// Sticky headers for tables.
		public static function TableStickyHeadersInit(&$state, &$options)
		{
			if (!isset($state["extras_table_stickyheaders"]))  $state["extras_table_stickyheaders"] = false;
		}

		public static function TableStickyHeadersTableRow(&$state, $num, &$field, $idbase, $type, $rownum, &$trattrs, &$colattrs, &$row)
		{
			if ($state["formtables"] && isset($field["stickyheader"]) && $field["stickyheader"])
			{
				if ($type == "head")
				{
					if ($state["extras_table_stickyheaders"] === false)
					{
						$state["js"]["table-sticky-headers"] = array("mode" => "src", "dependency" => "jquery", "src" => $state["supporturl"] . "/jquery.stickytableheaders.min.js", "detect" => "jQuery.fn.stickyTableHeaders");

						$state["extras_table_stickyheaders"] = true;
					}

					$options = array("__flexforms" => true);

					// Allow each sticky headers instance to be fully customized beyond basic support.
					// Valid options:  https://github.com/jmosbech/StickyTableHeaders
					if (isset($field["stickyheader_options"]))
					{
						foreach ($field["stickyheader_options"] as $key => $val)  $options[$key] = $val;
					}

					// Queue up the necessary Javascript for later output.
					ob_start();
?>
(function() {
	if (jQuery.fn.stickyTableHeaders)
	{
		var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
<?php
				if (isset($field["stickyheader_callbacks"]))
				{
					foreach ($field["stickyheader_callbacks"] as $key => $val)
					{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
					}
				}
?>

		if (!options['scrollableArea'])
		{
			jQuery('#<?php echo FlexForms::JSSafe($idbase); ?>').parents().each(function() {
				var innerheight = jQuery(this).innerHeight();

				if (!options['scrollableArea'] && innerheight > 0 && this.scrollHeight - 5 > innerheight)  options['scrollableArea'] = this;
			});
		}

		var recreatetimeout = null;

		jQuery('#<?php echo FlexForms::JSSafe($idbase); ?>').stickyTableHeaders(options).on('table:columnschanged', function() {
			if (recreatetimeout)  clearTimeout(recreatetimeout);
			else  jQuery('#<?php echo FlexForms::JSSafe($idbase); ?>').stickyTableHeaders('destroy').find('thead').attr('style', '').find('th').css('min-width', '0').css('max-width', 'none');

			recreatetimeout = setTimeout(function() { jQuery('#<?php echo FlexForms::JSSafe($idbase); ?>').stickyTableHeaders(options) }, 50);
		}).on('table:datachanged', function() {
			jQuery(options['scrollableArea'] ? options['scrollableArea'] : window).trigger('resize.stickyTableHeaders');
		});

		var resizefunc = function() {
			var obj = jQuery('#<?php echo FlexForms::JSSafe($idbase); ?>');

			if (!obj.length)  jQuery(window).off('resize', resizefunc).off('child:visibility', resizefunc);
			else  jQuery(options['scrollableArea'] ? options['scrollableArea'] : window).trigger('resize.stickyTableHeaders');
		};

		jQuery(window).resize(resizefunc).on('child:visibility', resizefunc);
	}
	else
	{
		alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing jQuery Sticky Table Headers plugin.\n\nThis feature requires FlexForms Extras.")); ?>');
	}
})();
<?php
					$state["js"]["table-sticky-headers|" . $idbase] = array("mode" => "inline", "dependency" => "table-sticky-headers", "src" => ob_get_contents());
					ob_end_clean();
				}
			}
		}


		// Body scrolling for tables.
		public static function TableBodyScrollInit(&$state, &$options)
		{
			if (!isset($state["extras_table_bodyscroll"]))  $state["extras_table_bodyscroll"] = false;
		}

		public static function TableBodyScrollFieldType(&$state, $num, &$field, $id)
		{
			if ($field["type"] == "table" && isset($field["bodyscroll"]) && $field["bodyscroll"])
			{
				$idbase = $id . "_table";

				if ($state["extras_table_bodyscroll"] === false)
				{
					$state["css"]["table-body-scroll"] = array("mode" => "link", "dependency" => false, "src" => $state["supporturl"] . "/jquery.tablebodyscroll.css");
					$state["js"]["table-body-scroll"] = array("mode" => "src", "dependency" => "jquery", "src" => $state["supporturl"] . "/jquery.tablebodyscroll.js", "detect" => "jQuery.fn.TableBodyScroll");

					$state["extras_table_bodyscroll"] = true;
				}

				$options = array("__flexforms" => true);

				// Allow each table body scroll instance to be fully customized beyond basic support.
				// Valid options:  See 'jquery.tablecards.js' file.
				if (isset($field["bodyscroll_options"]))
				{
					foreach ($field["bodyscroll_options"] as $key => $val)  $options[$key] = $val;
				}

				// Queue up the necessary Javascript for later output.
				ob_start();
?>
(function() {
	if (jQuery.fn.TableBodyScroll)
	{
		var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
<?php
				if (isset($field["bodyscroll_callbacks"]))
				{
					foreach ($field["bodyscroll_callbacks"] as $key => $val)
					{
?>
		options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
					}
				}
?>

		jQuery('#<?php echo FlexForms::JSSafe($idbase); ?>').TableBodyScroll(options).on('table:columnschanged', function() {
			jQuery(this).trigger('tablebodyscroll:columnschanged');
		}).on('table:datachanged', function() {
			jQuery(this).trigger('tablebodyscroll:resize');
		}).on('tablebodyscroll:sizechanged', function() {
			jQuery(this).trigger('table:resized');
		});

		var resizefunc = function() {
			var obj = jQuery('#<?php echo FlexForms::JSSafe($idbase); ?>');

			if (!obj.length)  jQuery(window).off('resize', resizefunc).off('child:visibility', resizefunc);
			else  obj.trigger('tablebodyscroll:resize');
		};

		jQuery(window).resize(resizefunc).on('child:visibility', resizefunc);
	}
	else
	{
		alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing jQuery Table Body Scroll plugin.\n\nThis feature requires FlexForms Extras.")); ?>');
	}
})();
<?php
				$state["js"]["table-body-scroll|" . $idbase] = array("mode" => "inline", "dependency" => "table-body-scroll", "src" => ob_get_contents());
				ob_end_clean();
			}
		}
	}


	// Register form handlers.
	if (is_callable("FlexForms::RegisterFormHandler"))
	{
		FlexForms::RegisterFormHandler("field_type", "FlexFormsExtras::DateFieldType");

		FlexForms::RegisterFormHandler("init", "FlexFormsExtras::AccordionsInit");
		FlexForms::RegisterFormHandler("field_string", "FlexFormsExtras::AccordionsFieldString");
		FlexForms::RegisterFormHandler("field_type", "FlexFormsExtras::AccordionsCustomFieldType");
		FlexForms::RegisterFormHandler("cleanup", "FlexFormsExtras::AccordionsCleanup");

		FlexForms::RegisterFormHandler("init", "FlexFormsExtras::MultiselectInit");
		FlexForms::RegisterFormHandler("field_type", "FlexFormsExtras::MultiselectFieldType");

		FlexForms::RegisterFormHandler("init", "FlexFormsExtras::TableCardsInit");
		FlexForms::RegisterFormHandler("field_type", "FlexFormsExtras::TableCardsFieldType");

		FlexForms::RegisterFormHandler("init", "FlexFormsExtras::TableOrderInit");
		FlexForms::RegisterFormHandler("table_row", "FlexFormsExtras::TableOrderTableRow");

		FlexForms::RegisterFormHandler("init", "FlexFormsExtras::TableStickyHeadersInit");
		FlexForms::RegisterFormHandler("table_row", "FlexFormsExtras::TableStickyHeadersTableRow");

		FlexForms::RegisterFormHandler("init", "FlexFormsExtras::TableBodyScrollInit");
		FlexForms::RegisterFormHandler("field_type", "FlexFormsExtras::TableBodyScrollFieldType");
	}
?>