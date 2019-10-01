// jQuery plugin to scroll the body of long tables so the table fits on a single screen.
// (C) 2017 CubicleSoft.  All Rights Reserved.

(function($) {
	var debounce = function(func, wait) {
		var timeout = null;

		return function() {
			var context = this, args = arguments;
			var later = function() {
				timeout = null;

				func.apply(context, args);
			};

			if (timeout)  clearTimeout(timeout);
			timeout = setTimeout(later, wait);
		};
	};

	var debounce2 = function(func, wait, wait2) {
		var timeout = null, timeout2 = null;

		return function() {
			var context = this, args = arguments;
			var later = function() {
				clearTimeout(timeout);
				timeout = null;

				clearTimeout(timeout2);
				timeout2 = null;

				func.apply(context, args);
			};

			if (timeout)  clearTimeout(timeout);
			timeout = setTimeout(later, wait);

			if (!timeout2)  timeout2 = setTimeout(later, wait2);
		};
	};

	$.fn.TableBodyScroll = function(options) {
		this.each(function() {
			var $this = $(this);

			if ($this.parent().hasClass('tablebodyscroll-scroller3'))
			{
				var scroller3 = $this.parent();
				var scroller2 = scroller3.parent();
				var scroller = scroller2.parent();
				var wrapper = scroller.parent();
				var origparent = wrapper.parent();

				// Remove event handlers.
				scroller2.off('scroll.tablebodyscroll');
				scroller2.off('mousemove.tablebodyscroll');
				scroller2.off('keypress.tablebodyscroll');
				$this.off('tablebodyscroll:resize');
				$this.off('tablebodyscroll:columnschanged');

				// Move the table back to its original parent in the DOM.
				wrapper.insertBefore($this);
				wrapper.remove();

				// Clean up modified header/footer cells.
				$this.children('thead, tfoot').children('tr').children('th, td').each(function() {
					if ($(this).hasClass('tablebodyscroll-body-hide-cell'))
					{
						$(this).removeClass('tablebodyscroll-body-hide-cell');

						var div = $(this).children('.tablebodyscroll-body-hide-cell');
						div.insertBefore(div.contents()).remove();
					}
				});
			}
		});

		if (typeof(options) === 'string' && options === 'destroy')  return this;

		var settings = $.extend({}, $.fn.TableBodyScroll.defaults, options);

		return this.each(function() {
			var $this = $(this);

			// Wrap the table.
			var origparent = $this.parent();
			var scrollerindicator = $('<div></div>').addClass('tablebodyscroll-scroller-indicator').addClass('tablebodyscroll-scroller-indicator-hide');
			var scrollershadowtop = $('<div></div>').addClass('tablebodyscroll-scroller-shadow-top');
			var scrollershadowbottom = $('<div></div>').addClass('tablebodyscroll-scroller-shadow-bottom');
			var scroller3 = $('<div></div>').addClass('tablebodyscroll-scroller3').insertBefore($this).append($this);
			var scroller2 = $('<div></div>').addClass('tablebodyscroll-scroller2').insertBefore(scroller3).append(scroller3);
			var scroller = $('<div></div>').addClass('tablebodyscroll-scroller').insertBefore(scroller2).append(scroller2).append(scrollerindicator).append(scrollershadowtop).append(scrollershadowbottom);
			var wrapper = $('<div></div>').addClass('tablebodyscroll').insertBefore(scroller).append(scroller);

			// Generate header and footer tables.
			// Cloning has several mostly minor unresolveable issues but there is no other way to accurately make just the body of the table scroll.
			var origtheads = null;
			var newheadtable = null, newtheadcells = null, origtheadcells = null;

			var origtfoots = null;
			var newfoottable = null, newtfootcells = null, origtfootcells = null;

			var CloneHeadFoot = function() {
				origtheads = $this.children('thead');
				origtheadcells = origtheads.children('tr').children('th, td');
				if (origtheads.length)
				{
					origtheadcells.each(function() {
						if (!$(this).hasClass('tablebodyscroll-body-hide-cell'))
						{
							$(this).addClass('tablebodyscroll-body-hide-cell').append($('<div class="tablebodyscroll-body-hide-cell"></div>').append($(this).contents()));
						}
					});

					if (newheadtable)  newheadtable.remove();
					var newtheads = origtheads.clone(true, true);
					newtheads.find('id').removeAttr('id');
					newheadtable = $('<table></table>').attr('class', $this.attr('class')).addClass('tablebodyscroll-head').insertBefore(scroller).append(newtheads);
					wrapper.addClass('tablebodyscroll-has-head');
					newtheadcells = newtheads.children('tr').children('th, td');
				}
				else
				{
					wrapper.removeClass('tablebodyscroll-has-head');
				}

				origtfoots = $this.children('tfoot');
				origtfootcells = origtfoots.children('tr').children('th, td');
				if (origtfoots.length)
				{
					origtfootcells.each(function() {
						if (!$(this).hasClass('tablebodyscroll-body-hide-cell'))
						{
							$(this).addClass('tablebodyscroll-body-hide-cell').append($('<div class="tablebodyscroll-body-hide-cell"></div>').append($(this).contents()));
						}
					});

					if (newfoottable)  newfoottable.remove();
					var newtfoots = origtfoots.clone(true, true);
					newtfoots.find('id').removeAttr('id');
					newfoottable = $('<table></table>').attr('class', $this.attr('class')).addClass('tablebodyscroll-foot').insertAfter(scroller).append(newtfoots);
					wrapper.addClass('tablebodyscroll-has-foot');
					newtfootcells = newtfoots.children('tr').children('th, td');
				}
				else
				{
					wrapper.removeClass('tablebodyscroll-has-foot');
				}
			};

			var scrollbarheight = 17;

			var HandleScroll = function() {
				var tempheight = $this.outerHeight();
				if (tempheight < 1)  return;

				// Calculate new shadow.
				var currshadow = scroller.attr('data-tablebodyscroll-shadow') || 'shadow-none';
				var currpos = scroller2.scrollTop();
				var scrollerheight = scroller2.height();

				var newshadow;
				if (currpos > 1 && currpos + scrollerheight - scrollbarheight < tempheight - 1)  newshadow = 'shadow-both';
				else if (currpos > 1)  newshadow = 'shadow-top';
				else if (currpos + scrollerheight - scrollbarheight < tempheight - 1)  newshadow = 'shadow-bottom';
				else  newshadow = 'shadow-none';

//console.log('currshadow = ' + currshadow + ', newshadow = ' + newshadow + ', currpos = ' + currpos + ', scrollerheight = ' + scrollerheight + ', scrollbarheight = ' + scrollbarheight + ', total = ' + (currpos + scrollerheight - scrollbarheight) + ', table height = ' + tempheight);

				if (currshadow !== newshadow)  scroller.removeClass('tablebodyscroll-' + currshadow).addClass('tablebodyscroll-' + newshadow).attr('data-tablebodyscroll-shadow', newshadow);

				// Adjust scroll indicator.
				var child = scrollerindicator.get(0);

				child.style.top = ((currpos / (tempheight - scrollerheight + scrollbarheight)) * (scrollerheight - scrollbarheight - scrollerindicator.height())) + 'px';
			}

			var lastparentwidth = 0, lasttablewidth = 0;

			var HandleResize = function() {
				var currpos = scroller2.scrollTop();
				var tempheight = $this.outerHeight();

				var maxheight = (settings.heightunit == '%' ? Math.floor($(settings.percentelem).height() * settings.height / 100) + 'px' : settings.height + settings.heightunit);
				scroller.css('height', maxheight);
				maxheight = scroller.height();

				if (maxheight > tempheight)  scroller.height(tempheight);

				// Move the table back to its original parent in the DOM, measure the width, and move it back.
				origparent.append($this);
				var tempwidth = $this.outerWidth();
				scroller3.append($this);

				// Set the width of the scroller to the width of the table so that the inset shadows show properly and horizontal scrolling is correct.
				scroller.width(tempwidth);
				var origparentwidth = origparent.width();
				var newparentwidth = (origparentwidth < tempwidth ? origparentwidth : tempwidth);
				scroller3.width(newparentwidth);

//console.log('table height = ' + tempheight + ', max height = ' + maxheight + ', width = ' + tempwidth + ', origparentwidth = ' + origparentwidth);

				// Notify listeners.
				if (lastparentwidth != newparentwidth || lasttablewidth != tempwidth)
				{
					lastparentwidth = newparentwidth;
					lasttablewidth = tempwidth;

					setTimeout(function() { $this.trigger('tablebodyscroll:sizechanged'); }, 0);
				}

				// Resize thead and tfoot elements.
				if (origtheads.length)
				{
					for (var x = 0; x < origtheadcells.length; x++)
					{
						var origcell = origtheadcells.get(x);
						var newcell = newtheadcells.get(x);

						if (origcell && newcell)
						{
							var tempwidth2;

							// Deals with Google Chrome(!) + jQuery off-by-one errors.
							if (origcell.currentStyle)  tempwidth2 = origcell.currentStyle.margin;
							else if (window.getComputedStyle)  tempwidth2 = window.getComputedStyle(origcell, null).getPropertyValue('width');
							else  tempwidth2 = $(origcell).width();

							$(newcell).css({ 'min-width': tempwidth2 });
						}
					}
				}

				if (origtfoots.length)
				{
					for (var x = 0; x < origtfootcells.length; x++)
					{
						var origcell = origtfootcells.get(x);
						var newcell = newtfootcells.get(x);

						if (origcell && newcell)
						{
							var tempwidth2;

							// Deals with Google Chrome(!) + jQuery off-by-one errors.
							if (window.getComputedStyle)  tempwidth2 = window.getComputedStyle(origcell, null).getPropertyValue('width');
							else if (origcell.currentStyle)  tempwidth2 = origcell.currentStyle.margin;
							else  tempwidth2 = $(origcell).width();

							$(newcell).css({ 'min-width': tempwidth2 });
						}
					}
				}

				// Adjust scroller offsets.
				var parent = scroller.get(0);
				var child = scroller2.get(0);
				scrollbarheight = (child.offsetHeight - child.clientHeight);
				child.style.bottom = -scrollbarheight + "px";

				var dir = (window.getComputedStyle ? window.getComputedStyle(parent, null).getPropertyValue('direction') : parent.currentStyle.direction);

				if (dir == 'ltr')  child.style.right = -(child.offsetWidth - child.clientWidth) + "px";
				else
				{
					child.style.left = -(child.offsetWidth - child.clientWidth) + "px";
					child.style.right = '0px';
				}

				// Update the scroller's shadows.
				scroller2.scrollTop(currpos);
				HandleScroll();
			};

			CloneHeadFoot();

			var showingindicator = false;

			var DelayHideIndicator = debounce(function() {
				scrollerindicator.addClass('tablebodyscroll-scroller-indicator-hide');
				scrollerindicator.removeClass('tablebodyscroll-scroller-indicator-show');

				showingindicator = false;
			}, 1500);

			var ShowIndicator = function() {
				if (!showingindicator)
				{
					var currshadow = scroller.attr('data-tablebodyscroll-shadow') || 'shadow-none';

					if (currshadow !== 'shadow-none')
					{
						scrollerindicator.addClass('tablebodyscroll-scroller-indicator-show');
						scrollerindicator.removeClass('tablebodyscroll-scroller-indicator-hide');

						showingindicator = true;
					}
				}

				if (showingindicator)  DelayHideIndicator();
			};

			scroller2.on('scroll.tablebodyscroll', debounce2(function() {
				HandleScroll();

				ShowIndicator();
			}, 20, 50));

			scroller2.on('mousemove.tablebodyscroll', debounce2(function() {
				ShowIndicator();
			}, 20, 50));

			scroller2.on('keypress.tablebodyscroll', debounce2(function() {
				ShowIndicator();
			}, 20, 50));

			$this.on('tablebodyscroll:resize', debounce2(function() {
				HandleResize();
			}, 20, 100));

			setTimeout(HandleResize, 0);

			$this.on('tablebodyscroll:columnschanged', function() {
				// Rebuild header and footer tables and resize.
				CloneHeadFoot();

				HandleResize();
			});

			if (settings.postinit)  settings.postinit(this, settings);
		});
	}

	$.fn.TableBodyScroll.defaults = {
		'height' : 60,
		'heightunit' : '%',
		'percentelem' : window,
		'postinit' : null
	};
}(jQuery));
