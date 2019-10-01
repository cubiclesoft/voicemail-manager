// jQuery plugin to convert tables to responsive cards via templates.
// (C) 2017 CubicleSoft.  All Rights Reserved.

(function($) {
	$.fn.TableCards = function(options) {
		// Reset TableCards (e.g. card template changes).
		this.each(function() {
			var $this = $(this);

			$this.removeClass('tablecards');
			$this.removeClass('tablecard-show');
			$this.children('thead, tbody, tfoot').children('tr').children('th, td').each(function() {
				$this2 = $(this);

				if ($this2.hasClass('tablecard-col'))  $this2.remove();
				else  $this2.removeClass('tablecard-mode-card');
			});

			$this.off('tablecards:resize');
			$this.off('tablecards:datachanged');
		});

		if (typeof(options) === 'string' && options === 'destroy')  return this;

		var settings = $.extend({}, $.fn.TableCards.defaults, options);

		// Allows rotation through input templates per row.
		if (typeof(settings.head) === 'string')  settings.head = [settings.head];
		if (typeof(settings.body) === 'string')  settings.body = [settings.body];
		if (typeof(settings.foot) === 'string')  settings.foot = [settings.foot];

		// Tokenize the templates.
		var ParseTemplate = function(templatestr) {
			if (typeof(templatestr) === 'function')  return templatestr;

			var template = [];
			var tokens = templatestr.split(settings.tokenstart);
			template.push(tokens.shift());
			var zero = '0'.charCodeAt(0);
			var nine = '9'.charCodeAt(0);
			for (var x = 0; x < tokens.length; x++)
			{
				var str = tokens[x];

				if (str.length == 0)  template.push(settings.tokenstart);
				else
				{
					var x2;
					for (x2 = 0; x2 < str.length && str.charCodeAt(x2) >= zero && str.charCodeAt(x2) <= nine; x2++)
					{
					}

					if (x2 > 0)  template.push(parseInt(str.substr(0, x2), 10));

					template.push(str.substr(x2));
				}
			}

			return template;
		};

		var ParseTemplates = function(templates) {
			var result = [];

			for (var x = 0; x < templates.length; x++)  result.push(ParseTemplate(templates[x]));

			return result;
		};

		// Apply a template to one or more rows of data and generate card columns.
		var ApplyTemplates = function(trs, templates, tag, mainbody) {
			var num = 0;

			trs.each(function() {
				var template = templates[num];

				num++;
				if (num >= templates.length)  num = 0;

				var html = '';

				if (typeof(template) === 'function')  html += template();
				else
				{
					var children = $(this).children('th, td');

					for (var x = 0; x < template.length; x++)
					{
						if (typeof(template[x]) === 'string')  html += template[x];
						else if (template[x] > 0 && template[x] <= children.length)  html += $(children.get(template[x] - 1)).html();
					}
				}

				$(this).append('<' + tag + ' class="tablecard-col' + (mainbody ? ' tablecard-mode-card' : '') + '">' + html + '</' + tag + '>');
			});
		};

		// Add a class to always show specified columns when in card mode.
		var AddCardClass = function(trs) {
			if (settings.extracols.length)
			{
				trs.first().children('th.tablecard-col, td.tablecard-col').addClass('tablecard-mode-card');

				for (var x = 0; x < settings.extracols.length; x++)
				{
					var col = settings.extracols[x];
					if (typeof(col) !== 'number')  continue;

					if (col > 0)
					{
						trs.each(function() {
							$(this).children('th, td').slice(col - 1, col).addClass('tablecard-mode-card');
						});
					}
				}
			}
		};

		var headtemplates = null, bodytemplates, foottemplates;

		var Init = function($this) {
			if (!headtemplates)
			{
				headtemplates = ParseTemplates(settings.head);
				bodytemplates = ParseTemplates(settings.body);
				foottemplates = ParseTemplates(settings.foot);
			}

			// Add a class so that CSS can make any necessary adjustments.
			$this.addClass('tablecards');

			// Alter table header.
			var theads = $this.children('thead');
			if (!theads.length)
			{
				$this.prepend('<thead><tr></tr></thead>');
				theads = $this.children('thead');
			}
			var headtrs = theads.children('tr');
			ApplyTemplates(headtrs, headtemplates, 'th', false);
			AddCardClass(headtrs);

			// Alter table body.
			var tbodys = $this.children('tbody');
			var bodytrs = tbodys.children('tr');
			ApplyTemplates(bodytrs, bodytemplates, 'td', true);
			AddCardClass(bodytrs);

			// Alter table footer (if any).
			var tfoots = $this.children('tfoot');
			var foottrs = tfoots.children('tr');
			ApplyTemplates(foottrs, foottemplates, 'td', false);
			AddCardClass(foottrs);
		};

		return this.each(function() {
			var $this = $(this);
			var minwidth = settings.width;
			var resetminwidth = false;
			var currmode = 'table';
			var initialized = false;

			var HandleResize = function(e) {
				var tablewidth = $this.outerWidth();
				if (tablewidth < 1)  return;

				var parentwidth = $this.parent().innerWidth();

				if (!minwidth && currmode === 'table')
				{
					if (tablewidth > parentwidth + 1)  minwidth = tablewidth;
				}

				if (minwidth)
				{
					if ((parentwidth < minwidth || tablewidth > parentwidth + 1) && currmode === 'table')
					{
						if (!initialized)
						{
							Init($this);
							initialized = true;
						}

						$this.addClass('tablecard-show');
						if (!settings.extracols.length)  $this.addClass('tablecard-show-nohead');
						currmode = 'card';

//console.log('mode = ' + currmode + ', parentwidth = ' + parentwidth + ', minwidth = ' + minwidth + ', tablewidth = ' + tablewidth);

						setTimeout(function() { $this.trigger('tablecards:mode', currmode); }, 0);
					}
					else if (parentwidth > minwidth + 1 && currmode === 'card')
					{
						$this.removeClass('tablecard-show');
						if (!settings.extracols.length)  $this.removeClass('tablecard-show-nohead');
						currmode = 'table';

//console.log('mode = ' + currmode + ', parentwidth = ' + parentwidth + ', minwidth = ' + minwidth + ', tablewidth = ' + tablewidth);

						if (resetminwidth)
						{
							minwidth = settings.width;
							resetminwidth = false;

							setTimeout(HandleResize, 20);
						}

						setTimeout(function() { $this.trigger('tablecards:mode', currmode); }, 0);
					}
				}
			};

			$this.on('tablecards:resize', HandleResize);
			setTimeout(HandleResize, 0);

			$this.on('tablecards:datachanged', function() {
				if (currmode === 'card')  resetminwidth = true;
				else
				{
					minwidth = settings.width;
					setTimeout(HandleResize, 0);
				}
			});

			if (settings.postinit)  settings.postinit(this, settings);
		});
	};

	$.fn.TableCards.defaults = {
		'width' : null,
		'extracols' : [],
		'tokenstart' : '%',
		'head' : '&nbsp;',
		'body' : '&nbsp;',
		'foot' : '&nbsp;',
		'postinit' : null
	};
}(jQuery));
