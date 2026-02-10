/*
## Bootstrap 5 attribute/class compatibility shim for Bootstrap 3 runtime.
*/
'use strict';

(function() {
	function mapBootstrap5ToBootstrap3() {
		var attrMap = [
			['data-bs-toggle', 'data-toggle'],
			['data-bs-target', 'data-target'],
			['data-bs-dismiss', 'data-dismiss'],
			['data-bs-parent', 'data-parent'],
			['data-bs-spy', 'data-spy'],
			['data-bs-ride', 'data-ride']
		];

		for(var i = 0; i < attrMap.length; i++) {
			var srcAttr = attrMap[i][0];
			var dstAttr = attrMap[i][1];
			var nodes = document.querySelectorAll('[' + srcAttr + ']');
			for(var n = 0; n < nodes.length; n++) {
				if(!nodes[n].hasAttribute(dstAttr)) {
					nodes[n].setAttribute(dstAttr, nodes[n].getAttribute(srcAttr));
				}
			}
		}

		var dropdownEnds = document.querySelectorAll('.dropdown-menu-end');
		for(var d = 0; d < dropdownEnds.length; d++) {
			dropdownEnds[d].classList.add('dropdown-menu-right');
		}

		var floatEnds = document.querySelectorAll('.float-end');
		for(var fe = 0; fe < floatEnds.length; fe++) {
			floatEnds[fe].classList.add('pull-right');
		}

		var floatStarts = document.querySelectorAll('.float-start');
		for(var fs = 0; fs < floatStarts.length; fs++) {
			floatStarts[fs].classList.add('pull-left');
		}

		var hidden = document.querySelectorAll('.visually-hidden');
		for(var h = 0; h < hidden.length; h++) {
			hidden[h].classList.add('sr-only');
		}

		var hiddenFocusable = document.querySelectorAll('.visually-hidden-focusable');
		for(var hf = 0; hf < hiddenFocusable.length; hf++) {
			hiddenFocusable[hf].classList.add('sr-only-focusable');
		}

		var dismissible = document.querySelectorAll('.alert-dismissible');
		for(var a = 0; a < dismissible.length; a++) {
			dismissible[a].classList.add('alert-dismissable');
		}
	}

	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mapBootstrap5ToBootstrap3);
	} else {
		mapBootstrap5ToBootstrap3();
	}
})();
