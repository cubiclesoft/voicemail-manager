<?php
	// Add a URL preview option to all elements.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	class FlexForms_PreviewURL
	{
		public static function Init(&$state, &$options)
		{
			if (!isset($state["modules_previewurl"]))  $state["modules_previewurl"] = false;
		}

		public static function FieldType(&$state, $num, &$field, $id)
		{
			if (isset($field["previewurl"]) && $field["previewurl"])
			{
				if ($field["type"] === "table")  $id .= "_table";

				if ($state["modules_previewurl"] === false)
				{
					$state["css"]["modules-previewurl"] = array("mode" => "link", "dependency" => false, "src" => $state["supporturl"] . "/jquery.previewurl.css");
					$state["js"]["modules-previewurl"] = array("mode" => "src", "dependency" => "jquery", "src" => $state["supporturl"] . "/jquery.previewurl.js", "detect" => "jQuery.fn.PreviewURL");

					$state["modules_previewurl"] = true;
				}

				$options = array(
					"__flexforms" => true
				);

				// Allow each PreviewURL instance to be fully customized beyond basic support.
				// Valid options:  See 'jquery.previewurl.js' file.
				if (isset($field["previewurl_options"]))
				{
					foreach ($field["previewurl_options"] as $key => $val)  $options[$key] = $val;
				}

				// Queue up the necessary Javascript for later output.
				ob_start();
?>
			jQuery(function() {
				var options = <?php echo json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;

<?php
				if (isset($field["previewurl_callbacks"]))
				{
					foreach ($field["previewurl_callbacks"] as $key => $val)
					{
?>
				options['<?php echo $key; ?>'] = <?php echo $val; ?>;
<?php
					}
				}

				// Support for table cards and other modules that modify tables.
				if ($field["type"] === "table")
				{
?>
				jQuery('#<?php echo FlexForms::JSSafe($id); ?>').on('table:columnschanged', function() {
					jQuery('#<?php echo FlexForms::JSSafe($id); ?>').closest('.formitem').find('[data-preview-type]').PreviewURL(options);
				});
<?php
				}
?>

				jQuery('#<?php echo FlexForms::JSSafe($id); ?>').closest('.formitem').find('[data-preview-type]').PreviewURL(options);
			});
<?php
				$state["js"]["modules-previewurl|" . $id] = array("mode" => "inline", "dependency" => "modules-previewurl", "src" => ob_get_contents());
				ob_end_clean();
			}
		}
	}

	// Register form handlers.
	if (is_callable("FlexForms::RegisterFormHandler"))
	{
		FlexForms::RegisterFormHandler("init", "FlexForms_PreviewURL::Init");
		FlexForms::RegisterFormHandler("field_type", "FlexForms_PreviewURL::FieldType");
	}
?>