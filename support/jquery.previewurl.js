// jQuery plugin to display a referenced URL in a preview dialog.
// (C) 2018 CubicleSoft.  All Rights Reserved.

(function($) {
	// Create some extra DOM nodes for preview checking.
	var audioelem = document.createElement('audio');
	var videoelem = document.createElement('video');

	var DisplayPreviewDialog = function(preview, settings) {
		var url;
		if (preview[0].hasAttribute('data-preview-url'))  url = preview.attr('data-preview-url');
		else if (preview[0].hasAttribute('href'))  url = preview.attr('href');
		else if (preview[0].hasAttribute('src'))  url = preview.attr('src');
		else  return false;

		var previewtype = preview.attr('data-preview-type');

		var previewclone;
		if (previewtype && previewtype === 'image/gif' || previewtype === 'image/jpeg' || previewtype === 'image/png')  previewclone = $('<img>').attr('src', url);
		else if (previewtype && previewtype.lastIndexOf('audio/', 0) > -1 && audioelem.canPlayType && audioelem.canPlayType(previewtype))  previewclone = $('<audio>').attr('src', url).prop('controls', true);
		else if (previewtype && previewtype.lastIndexOf('video/', 0) > -1 && videoelem.canPlayType && videoelem.canPlayType(previewtype))  previewclone = $('<video>').attr('src', url).prop('controls', true);
		else if (previewtype && previewtype === 'iframe')  previewclone = $('<iframe>').attr('src', url).attr('frameborder', '0').attr('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups').attr('allow', 'autoplay; encrypted-media');
		else  return false;

		var previewbackground = $('<div>').addClass('previewurl_dialog_background');

		previewclone.click(function(e) {
			e.stopPropagation();
		});

		var previewdialog = $('<div>').addClass('previewurl_dialog_main').append(previewclone);

		var HidePreviewDialog = function() {
			$(document).off('keyup.previewurl');

			previewbackground.remove();
			preview.focus();

			if (settings.hidepreview)  settings.hidepreview.call(preview, settings.hidepreviewinfo, previewclone);
		};

		$(document).on('keyup.previewurl', function(e) {
			if (e.keyCode == 27) {
				HidePreviewDialog();
			}
		});

		previewbackground.append(previewdialog).click(function() {
			HidePreviewDialog();
		});

		$('body').append(previewbackground);
		previewclone.focus();

		if (settings.showpreview)  settings.showpreview.call(preview, settings.showpreviewinfo, previewclone);

		return true;
	};

	$.fn.PreviewURL = function(options) {
		this.each(function() {
			var $this = $(this);

			// Remove event handlers.
			$this.off('click.previewurl');
		});

		if (typeof(options) === 'string' && options === 'destroy')  return this;

		var settings = $.extend($.fn.PreviewURL.defaults, options);

		return this.each(function() {
			var $this = $(this);

			var PreviewHandler = function(e) {
				if (DisplayPreviewDialog($this, settings))  e.preventDefault();
			};

			$this.on('click.previewurl', PreviewHandler);
		});
	}

	$.fn.PreviewURL.defaults = {
		'showpreview' : null,
		'showpreviewinfo' : null,
		'hidepreview' : null,
		'hidepreviewinfo' : null
	};
}(jQuery));
