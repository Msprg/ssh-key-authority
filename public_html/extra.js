/*
##
## Copyright 2013-2017 Opera Software AS
## Modifications Copyright 2021 Leitwerk AG
##
## Licensed under the Apache License, Version 2.0 (the "License");
## you may not use this file except in compliance with the License.
## You may obtain a copy of the License at
##
## http://www.apache.org/licenses/LICENSE-2.0
##
## Unless required by applicable law or agreed to in writing, software
## distributed under the License is distributed on an "AS IS" BASIS,
## WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
## See the License for the specific language governing permissions and
## limitations under the License.
##
*/
'use strict';

function dom_ready(callback) {
	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', callback);
	} else {
		callback();
	}
}

var SKA_SHORT_TRANSITION_MS = 150;
var SKA_COLLAPSE_TRANSITION_MS = 350;

function force_reflow(element) {
	return element.offsetHeight;
}

function clear_dynamic_animation(element) {
	if(!element) {
		return;
	}

	if(element.__skaVisibilityTimer) {
		window.clearTimeout(element.__skaVisibilityTimer);
		element.__skaVisibilityTimer = null;
	}
}

function show_dynamic_element(element, animate) {
	if(!element) {
		return;
	}

	clear_dynamic_animation(element);
	element.classList.remove('hide', 'hidden', 'd-none');
	if(!animate) {
		element.style.display = '';
		element.style.removeProperty('height');
		element.style.removeProperty('opacity');
		element.style.removeProperty('overflow');
		element.style.removeProperty('transition');
		return;
	}

	element.style.removeProperty('display');
	element.style.overflow = 'hidden';
	element.style.height = '0px';
	element.style.opacity = '0';
	element.style.transition = 'height ' + SKA_SHORT_TRANSITION_MS + 'ms ease, opacity ' + SKA_SHORT_TRANSITION_MS + 'ms ease';
	force_reflow(element);
	element.style.height = element.scrollHeight + 'px';
	element.style.opacity = '1';
	element.__skaVisibilityTimer = window.setTimeout(function() {
		element.style.removeProperty('height');
		element.style.removeProperty('opacity');
		element.style.removeProperty('overflow');
		element.style.removeProperty('transition');
		element.__skaVisibilityTimer = null;
	}, SKA_SHORT_TRANSITION_MS);
}

function hide_dynamic_element(element, animate) {
	if(!element) {
		return;
	}

	clear_dynamic_animation(element);
	element.classList.remove('hide', 'hidden', 'd-none');
	if(!animate) {
		element.style.display = 'none';
		element.style.removeProperty('height');
		element.style.removeProperty('opacity');
		element.style.removeProperty('overflow');
		element.style.removeProperty('transition');
		return;
	}

	element.style.overflow = 'hidden';
	element.style.height = element.scrollHeight + 'px';
	element.style.opacity = '1';
	element.style.transition = 'height ' + SKA_SHORT_TRANSITION_MS + 'ms ease, opacity ' + SKA_SHORT_TRANSITION_MS + 'ms ease';
	force_reflow(element);
	element.style.height = '0px';
	element.style.opacity = '0';
	element.__skaVisibilityTimer = window.setTimeout(function() {
		element.style.display = 'none';
		element.style.removeProperty('height');
		element.style.removeProperty('opacity');
		element.style.removeProperty('overflow');
		element.style.removeProperty('transition');
		element.__skaVisibilityTimer = null;
	}, SKA_SHORT_TRANSITION_MS);
}

function toggle_dynamic_element(element) {
	if(!element) {
		return;
	}

	if(window.getComputedStyle(element).display === 'none') {
		show_dynamic_element(element, true);
	} else {
		hide_dynamic_element(element, true);
	}
}

function set_section_visibility(element, visible, animate) {
	if(visible) {
		show_dynamic_element(element, animate);
	} else {
		hide_dynamic_element(element, animate);
	}
}

// Handle 'navigate-back' links
dom_ready(function() {
	var links = document.querySelectorAll('a.navigate-back');
	for(var i = 0; i < links.length; i++) {
		links[i].addEventListener('click', function(event) {
			event.preventDefault();
			window.history.back();
			event.stopPropagation();
		});
	}
});

// Native dropdown and alert-dismiss behavior for the base shell.
(function() {
	var dropdownSelector = '[data-bs-toggle="dropdown"]';
	var alertDismissSelector = '[data-bs-dismiss="alert"]';

	function dispatch_bootstrap_event(target, type, relatedTarget) {
		return target.dispatchEvent(new CustomEvent(type, {
			bubbles: true,
			cancelable: true,
			detail: {relatedTarget: relatedTarget || null}
		}));
	}

	function get_dropdown_root(trigger) {
		return trigger.closest('.dropdown') || trigger.parentElement;
	}

	function get_dropdown_menu(root) {
		if(!root) {
			return null;
		}
		return root.querySelector('.dropdown-menu');
	}

	function close_dropdown(root, trigger, relatedTarget) {
		if(!root || !root.classList.contains('open')) {
			return;
		}

		var menu = get_dropdown_menu(root);
		if(!dispatch_bootstrap_event(root, 'hide.bs.dropdown', relatedTarget || trigger)) {
			return;
		}

		root.classList.remove('open');
		if(trigger) {
			trigger.setAttribute('aria-expanded', 'false');
		}
		if(menu) {
			menu.setAttribute('aria-hidden', 'true');
		}

		dispatch_bootstrap_event(root, 'hidden.bs.dropdown', relatedTarget || trigger);
	}

	function close_other_dropdowns(currentRoot, relatedTarget) {
		var openDropdowns = document.querySelectorAll('.dropdown.open');
		for(var i = 0; i < openDropdowns.length; i++) {
			if(openDropdowns[i] === currentRoot) {
				continue;
			}

			var trigger = openDropdowns[i].querySelector(dropdownSelector);
			close_dropdown(openDropdowns[i], trigger, relatedTarget);
		}
	}

	function open_dropdown(root, trigger) {
		var menu = get_dropdown_menu(root);
		if(!root || !menu) {
			return;
		}

		close_other_dropdowns(root, trigger);
		if(root.classList.contains('open')) {
			return;
		}

		if(!dispatch_bootstrap_event(root, 'show.bs.dropdown', trigger)) {
			return;
		}

		root.classList.add('open');
		trigger.setAttribute('aria-expanded', 'true');
		menu.setAttribute('aria-hidden', 'false');

		dispatch_bootstrap_event(root, 'shown.bs.dropdown', trigger);
	}

	document.addEventListener('click', function(event) {
		var dropdownTrigger = event.target.closest(dropdownSelector);
		if(dropdownTrigger) {
			var dropdownRoot = get_dropdown_root(dropdownTrigger);
			if(!dropdownRoot) {
				return;
			}

			event.preventDefault();
			if(dropdownRoot.classList.contains('open')) {
				close_dropdown(dropdownRoot, dropdownTrigger, dropdownTrigger);
			} else {
				open_dropdown(dropdownRoot, dropdownTrigger);
			}
			return;
		}

		var dismissButton = event.target.closest(alertDismissSelector);
		if(dismissButton) {
			var alertTargetSelector = dismissButton.getAttribute('data-bs-target') || dismissButton.getAttribute('data-target');
			var alertElement = null;

			if(alertTargetSelector && alertTargetSelector.indexOf('#') === 0) {
				alertElement = document.querySelector(alertTargetSelector);
			}
			if(!alertElement) {
				alertElement = dismissButton.closest('.alert');
			}
			if(!alertElement) {
				return;
			}

			event.preventDefault();
			if(!dispatch_bootstrap_event(alertElement, 'close.bs.alert', dismissButton)) {
				return;
			}

			alertElement.parentNode.removeChild(alertElement);
			dispatch_bootstrap_event(alertElement, 'closed.bs.alert', dismissButton);
			return;
		}

		close_other_dropdowns(null, event.target);
	});

	document.addEventListener('keydown', function(event) {
		if(event.key !== 'Escape') {
			return;
		}

		var openDropdowns = document.querySelectorAll('.dropdown.open');
		for(var i = 0; i < openDropdowns.length; i++) {
			var trigger = openDropdowns[i].querySelector(dropdownSelector);
			close_dropdown(openDropdowns[i], trigger, event.target);
			if(trigger) {
				trigger.focus();
			}
		}
	});
})();

// Bootstrap 5-compatible tab behavior for migrated tabsets.
dom_ready(function() {
	var selector = '[data-bs-toggle="tab"]';
	var migratedTabs = document.querySelectorAll(selector);

	if(!migratedTabs.length) {
		return;
	}

	function get_tab_target(link) {
		var target = link.getAttribute('data-bs-target') || link.getAttribute('href') || '';
		if(target.indexOf('#') !== 0) {
			return null;
		}
		return document.getElementById(target.substring(1));
	}

	function get_tab_group(link) {
		return link.closest('.nav-tabs, .nav-pills');
	}

	function find_active_tab(links) {
		for(var i = 0; i < links.length; i++) {
			if(links[i].classList.contains('active')) {
				return links[i];
			}

			var item = links[i].closest('li');
			if(item && item.classList.contains('active')) {
				return links[i];
			}
		}

		return null;
	}

	function set_history_hash(hash) {
		if(window.history && history.replaceState) {
			history.replaceState(null, null, hash);
		} else {
			window.location.hash = hash;
		}
	}

	function show_tab(link, updateHash, animate) {
		var group = get_tab_group(link);
		var target = get_tab_target(link);
		if(!group || !target) {
			return;
		}

		var links = group.querySelectorAll(selector);
		var previous = find_active_tab(links);
		var showEvent = new CustomEvent('show.bs.tab', {
			bubbles: true,
			cancelable: true,
			detail: {relatedTarget: previous}
		});
		if(!link.dispatchEvent(showEvent)) {
			return;
		}

		for(var i = 0; i < links.length; i++) {
			links[i].classList.remove('active');
			links[i].setAttribute('aria-selected', 'false');
			links[i].setAttribute('tabindex', '-1');

			var item = links[i].closest('li');
			if(item) {
				item.classList.remove('active');
			}
		}

		var tabContent = target.closest('.tab-content');
		if(tabContent) {
			var panes = tabContent.querySelectorAll('.tab-pane');
			for(var p = 0; p < panes.length; p++) {
				panes[p].classList.remove('active');
				panes[p].classList.remove('in');
				panes[p].classList.remove('show');
				panes[p].setAttribute('aria-hidden', 'true');
			}
		}

		link.classList.add('active');
		link.setAttribute('aria-selected', 'true');
		link.removeAttribute('tabindex');

		var activeItem = link.closest('li');
		if(activeItem) {
			activeItem.classList.add('active');
		}

		target.classList.add('active');
		target.classList.add('show');
		if(target.classList.contains('fade')) {
			if(animate) {
				target.classList.remove('in');
				force_reflow(target);
				window.requestAnimationFrame(function() {
					target.classList.add('in');
				});
			} else {
				target.classList.add('in');
			}
		}
		target.setAttribute('aria-hidden', 'false');

		if(updateHash) {
			set_history_hash('#' + target.id);
		}

		link.dispatchEvent(new CustomEvent('shown.bs.tab', {
			bubbles: true,
			detail: {relatedTarget: previous}
		}));
	}

	for(var t = 0; t < migratedTabs.length; t++) {
		migratedTabs[t].addEventListener('click', function(event) {
			event.preventDefault();
			show_tab(this, true, true);
		});
	}

	var groups = document.querySelectorAll('.nav-tabs, .nav-pills');
	var fragment = window.location.hash ? window.location.hash.substring(1) : '';

	for(var g = 0; g < groups.length; g++) {
		var groupLinks = groups[g].querySelectorAll(selector);
		if(!groupLinks.length) {
			continue;
		}

		var initial = null;
		if(fragment) {
			for(var h = 0; h < groupLinks.length; h++) {
				var target = get_tab_target(groupLinks[h]);
				if(target && target.id === fragment) {
					initial = groupLinks[h];
					break;
				}
			}
		}

		if(!initial) {
			initial = find_active_tab(groupLinks) || groupLinks[0];
		}

		show_tab(initial, false, false);
	}
});

// Remember the expanded-state of a collapsible section
dom_ready(function() {
	var migratedCollapseSelector = '.collapse[data-bs-parent], .collapse.ska-collapse-target, .ska-card-collapse.collapse';
	var migratedCollapseTriggers = document.querySelectorAll('[data-bs-toggle="collapse"]');

	function get_collapse_target(trigger) {
		var target = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href') || '';
		if(target.indexOf('#') !== 0) {
			return null;
		}
		return document.getElementById(target.substring(1));
	}

	function get_collapse_triggers(collapse) {
		var triggers = [];
		for(var i = 0; i < migratedCollapseTriggers.length; i++) {
			if(get_collapse_target(migratedCollapseTriggers[i]) === collapse) {
				triggers.push(migratedCollapseTriggers[i]);
			}
		}
		return triggers;
	}

	function finish_collapse_animation(collapse, expanded) {
		collapse.classList.remove('collapsing');
		collapse.classList.add('collapse');
		collapse.classList.toggle('in', expanded);
		collapse.classList.toggle('show', expanded);
		collapse.style.removeProperty('height');
		collapse.__skaCollapseTimer = null;
	}

	function set_collapse_state(collapse, expanded, animate) {
		var triggers = get_collapse_triggers(collapse);
		collapse.setAttribute('aria-hidden', expanded ? 'false' : 'true');

		for(var i = 0; i < triggers.length; i++) {
			triggers[i].setAttribute('aria-expanded', expanded ? 'true' : 'false');
			triggers[i].classList.toggle('collapsed', !expanded);
		}

		if(collapse.__skaCollapseTimer) {
			window.clearTimeout(collapse.__skaCollapseTimer);
			collapse.__skaCollapseTimer = null;
		}

		if(!animate) {
			collapse.classList.remove('collapsing');
			collapse.classList.add('collapse');
			collapse.classList.toggle('in', expanded);
			collapse.classList.toggle('show', expanded);
			collapse.style.removeProperty('height');
			return;
		}

		if(expanded) {
			collapse.classList.remove('collapse', 'in', 'show');
			collapse.classList.add('collapsing');
			collapse.style.height = '0px';
			force_reflow(collapse);
			collapse.style.height = collapse.scrollHeight + 'px';
		} else {
			collapse.style.height = collapse.scrollHeight + 'px';
			force_reflow(collapse);
			collapse.classList.remove('collapse', 'in', 'show');
			collapse.classList.add('collapsing');
			collapse.style.height = '0px';
		}

		collapse.__skaCollapseTimer = window.setTimeout(function() {
			finish_collapse_animation(collapse, expanded);
		}, SKA_COLLAPSE_TRANSITION_MS);
	}

	function collapse_related_sections(trigger, collapse, animate) {
		var parentSelector = trigger.getAttribute('data-bs-parent') || collapse.getAttribute('data-bs-parent');
		if(!parentSelector) {
			return;
		}

		var parent = document.querySelector(parentSelector);
		if(!parent) {
			return;
		}

		var related = parent.querySelectorAll(migratedCollapseSelector);
		for(var i = 0; i < related.length; i++) {
			if(related[i] !== collapse) {
				set_collapse_state(related[i], false, animate);
			}
		}
	}

	function get_section_fragment() {
		var url = document.location.toString();
		if(url.match('#')) {
			return url.split('#')[1];
		}
		return '';
	}

	function sync_migrated_collapses_from_location() {
		var fragment = get_section_fragment();
		var collapses = document.querySelectorAll(migratedCollapseSelector);

		for(var i = 0; i < collapses.length; i++) {
			var expanded = collapses[i].id === fragment;
			if(expanded) {
				var triggers = get_collapse_triggers(collapses[i]);
				if(triggers.length) {
					collapse_related_sections(triggers[0], collapses[i], false);
				}
			}
			set_collapse_state(collapses[i], expanded, false);
		}
	}

	for(var c = 0; c < migratedCollapseTriggers.length; c++) {
		migratedCollapseTriggers[c].addEventListener('click', function(event) {
			var collapse = get_collapse_target(this);
			if(!collapse) {
				return;
			}

			event.preventDefault();
			var expanded = !collapse.classList.contains('in');
			if(expanded) {
				var showEvent = new CustomEvent('show.bs.collapse', {
					bubbles: true,
					cancelable: true
				});
				if(!collapse.dispatchEvent(showEvent)) {
					return;
				}
				collapse_related_sections(this, collapse, true);
				set_collapse_state(collapse, true, true);
				if(history) {
					history.replaceState(null, null, '#' + collapse.id);
				} else {
					window.location.hash = collapse.id;
				}
				window.setTimeout(function() {
					collapse.dispatchEvent(new CustomEvent('shown.bs.collapse', {bubbles: true}));
				}, SKA_COLLAPSE_TRANSITION_MS);
			} else {
				var hideEvent = new CustomEvent('hide.bs.collapse', {
					bubbles: true,
					cancelable: true
				});
				if(!collapse.dispatchEvent(hideEvent)) {
					return;
				}
				set_collapse_state(collapse, false, true);
				window.setTimeout(function() {
					collapse.dispatchEvent(new CustomEvent('hidden.bs.collapse', {bubbles: true}));
				}, SKA_COLLAPSE_TRANSITION_MS);
			}
		});
	}

	if(document.querySelector(migratedCollapseSelector)) {
		sync_migrated_collapses_from_location();
	}

	window.addEventListener('popstate', function() {
		sync_migrated_collapses_from_location();
	});
});

// Show only chosen fingerprint hash format in list views
dom_ready(function() {
	var fingerprintHeader = document.querySelector('table th.fingerprint');
	if(!fingerprintHeader) {
		return;
	}

	var select = document.createElement('select');
	var options = ['MD5', 'SHA256'];
	for(var i = 0; i < options.length; i++) {
		var option = document.createElement('option');
		option.value = options[i];
		option.textContent = options[i];
		select.appendChild(option);
	}

	if(window.localStorage) {
		var preferredHash = localStorage.getItem('preferred_fingerprint_hash');
		if(preferredHash) {
			select.value = preferredHash;
		}
	}

	function update_fingerprint_visibility() {
		var showSha256 = select.value === 'SHA256';
		var md5Fingerprints = document.querySelectorAll('span.fingerprint_md5');
		var sha256Fingerprints = document.querySelectorAll('span.fingerprint_sha256');

		for(var j = 0; j < md5Fingerprints.length; j++) {
			md5Fingerprints[j].style.display = showSha256 ? 'none' : '';
		}
		for(var k = 0; k < sha256Fingerprints.length; k++) {
			sha256Fingerprints[k].style.display = showSha256 ? '' : 'none';
		}

		if(window.localStorage) {
			localStorage.setItem('preferred_fingerprint_hash', select.value);
		}
	}

	fingerprintHeader.appendChild(document.createTextNode(' '));
	fingerprintHeader.appendChild(select);
	select.addEventListener('change', update_fingerprint_visibility);
	update_fingerprint_visibility();
});

// Add confirmation dialog to all submit buttons with data-confirm attribute
dom_ready(function() {
	var buttons = document.querySelectorAll('button[type="submit"][data-confirm]');
	for(var i = 0; i < buttons.length; i++) {
		buttons[i].addEventListener('click', function(event) {
			if(!window.confirm(this.getAttribute('data-confirm'))) {
				event.preventDefault();
			}
		});
	}
});

// Add "clear field" button functionality
dom_ready(function() {
	var buttons = document.querySelectorAll('button[data-clear]');
	for(var i = 0; i < buttons.length; i++) {
		buttons[i].addEventListener('click', function() {
			if(!this.form) {
				return;
			}

			var fieldName = this.getAttribute('data-clear');
			var field = this.form.elements[fieldName];
			if(field) {
				field.value = '';
			}
		});
	}
});

// Home page dynamic add pubkey form
dom_ready(function() {
	var addKeyButton = document.getElementById('add_key_button');
	var help = document.getElementById('help');
	var addKeyForm = document.getElementById('add_key_form');
	var addPublicKey = document.getElementById('add_public_key');

	if(!addKeyButton || !addKeyForm) {
		return;
	}

	addKeyButton.addEventListener('click', function(event) {
		event.preventDefault();
		hide_dynamic_element(help, false);
		show_dynamic_element(addKeyForm, true);
		hide_dynamic_element(addKeyButton, true);
		if(addPublicKey) {
			addPublicKey.focus();
		}
	});

	var helpButtons = addKeyForm.querySelectorAll('[data-action="toggle-help"], button[type="button"].btn-info');
	for(var i = 0; i < helpButtons.length; i++) {
		helpButtons[i].addEventListener('click', function() {
			toggle_dynamic_element(help);
		});
	}

	var cancelButtons = addKeyForm.querySelectorAll('[data-action="cancel-add-key"], button[type="button"].btn-secondary');
	for(var j = 0; j < cancelButtons.length; j++) {
		cancelButtons[j].addEventListener('click', function() {
			hide_dynamic_element(addKeyForm, true);
			show_dynamic_element(addKeyButton, true);
		});
	}
});

// Show/hide appropriate sections of the server settings form
dom_ready(function() {
	var form = document.getElementById('server_settings');
	if(!form) {
		return;
	}

	var authorizationSection = document.getElementById('authorization');
	var ldapAccessOptionsSection = document.getElementById('ldap_access_options');
	var historyUsernameEnvSection = document.getElementById('history_username_env');
	var supervisionSection = document.getElementById('supervision');
	var keyManagementInputs = form.querySelectorAll('input[name="key_management"]');
	var authorizationInputs = form.querySelectorAll('input[name="authorization"]');
	var commandEnabled = form.elements['access_option[command][enabled]'];
	var commandValue = form.elements['access_option[command][value]'];
	var fromEnabled = form.elements['access_option[from][enabled]'];
	var fromValue = form.elements['access_option[from][value]'];

	function get_checked_value(inputs) {
		for(var i = 0; i < inputs.length; i++) {
			if(inputs[i].checked) {
				return inputs[i].value;
			}
		}
		return '';
	}

	function update_relevant_options() {
		var managesKeys = get_checked_value(keyManagementInputs) === 'keys';
		set_section_visibility(authorizationSection, managesKeys, true);
		set_section_visibility(supervisionSection, managesKeys, true);
		set_section_visibility(historyUsernameEnvSection, managesKeys, true);
		set_section_visibility(ldapAccessOptionsSection, managesKeys && get_checked_value(authorizationInputs) !== 'manual', true);
	}

	function update_disabled_fields() {
		if(commandValue && commandEnabled) {
			commandValue.disabled = !commandEnabled.checked;
			commandValue.required = commandEnabled.checked;
		}
		if(fromValue && fromEnabled) {
			fromValue.disabled = !fromEnabled.checked;
			fromValue.required = fromEnabled.checked;
		}
	}

	for(var i = 0; i < keyManagementInputs.length; i++) {
		keyManagementInputs[i].addEventListener('click', update_relevant_options);
	}
	for(var j = 0; j < authorizationInputs.length; j++) {
		authorizationInputs[j].addEventListener('click', update_relevant_options);
	}
	if(commandEnabled) {
		commandEnabled.addEventListener('click', update_disabled_fields);
	}
	if(fromEnabled) {
		fromEnabled.addEventListener('click', update_disabled_fields);
	}

	set_section_visibility(authorizationSection, get_checked_value(keyManagementInputs) === 'keys', false);
	set_section_visibility(supervisionSection, get_checked_value(keyManagementInputs) === 'keys', false);
	set_section_visibility(historyUsernameEnvSection, get_checked_value(keyManagementInputs) === 'keys', false);
	set_section_visibility(ldapAccessOptionsSection, get_checked_value(keyManagementInputs) === 'keys' && get_checked_value(authorizationInputs) !== 'manual', false);
	update_disabled_fields();
});

// Enable/disable relevant sections of the access options form
dom_ready(function() {
	var form = document.getElementById('access_options');
	if(!form) {
		return;
	}

	var commandEnabled = form.elements['access_option[command][enabled]'];
	var commandValue = form.elements['access_option[command][value]'];
	var fromEnabled = form.elements['access_option[from][enabled]'];
	var fromValue = form.elements['access_option[from][value]'];
	var noPortForwardingEnabled = form.elements['access_option[no-port-forwarding][enabled]'];
	var noX11ForwardingEnabled = form.elements['access_option[no-X11-forwarding][enabled]'];
	var noPtyEnabled = form.elements['access_option[no-pty][enabled]'];
	var presetButtons = form.querySelectorAll('button[type="button"][data-preset]');

	function update_disabled_fields() {
		if(commandValue && commandEnabled) {
			commandValue.disabled = !commandEnabled.checked;
			commandValue.required = commandEnabled.checked;
		}
		if(fromValue && fromEnabled) {
			fromValue.disabled = !fromEnabled.checked;
			fromValue.required = fromEnabled.checked;
		}
	}

	function reset_checkboxes() {
		var checkboxes = form.querySelectorAll('input[type="checkbox"]');
		for(var i = 0; i < checkboxes.length; i++) {
			checkboxes[i].checked = false;
		}
	}

	if(commandEnabled) {
		commandEnabled.addEventListener('click', update_disabled_fields);
	}
	if(fromEnabled) {
		fromEnabled.addEventListener('click', update_disabled_fields);
	}

	for(var i = 0; i < presetButtons.length; i++) {
		presetButtons[i].addEventListener('click', function() {
			var preset = this.getAttribute('data-preset');
			if(!preset) {
				return;
			}

			reset_checkboxes();
			if(commandValue) {
				commandValue.value = '';
			}
			if(fromValue) {
				fromValue.value = '';
			}

			if(preset === 'command' || preset === 'dbbackup' || preset === 'checkmk') {
				if(commandEnabled) {
					commandEnabled.checked = true;
				}
				if(noPortForwardingEnabled) {
					noPortForwardingEnabled.checked = true;
				}
				if(noX11ForwardingEnabled) {
					noX11ForwardingEnabled.checked = true;
				}
				if(noPtyEnabled) {
					noPtyEnabled.checked = true;
				}
				if(commandValue) {
					commandValue.focus();
				}
			}

			if(commandValue && preset === 'dbbackup') {
				commandValue.value = '/usr/bin/innobackupex --slave-info --defaults-file=/etc/mysql/my.cnf /var/tmp';
			} else if(commandValue && preset === 'checkmk') {
				commandValue.value = '/usr/bin/check_mk_agent';
			}

			update_disabled_fields();
		});
	}

	update_disabled_fields();
});

// Provide dynamic reassign form on user page
dom_ready(function() {
	var buttons = document.querySelectorAll('button[data-reassign]');
	for(var i = 0; i < buttons.length; i++) {
		buttons[i].addEventListener('click', function() {
			var id = this.getAttribute('data-reassign');
			var table = document.getElementById(id);
			var parent = this.parentElement;
			if(!table || !parent) {
				return;
			}

			var headerRow = table.querySelector('thead tr');
			var bodyRows = table.querySelectorAll('tbody tr');
			var selectAllCell = document.createElement('th');
			var selectAllCheckbox = document.createElement('input');
			selectAllCheckbox.type = 'checkbox';
			selectAllCell.appendChild(selectAllCheckbox);
			if(headerRow) {
				headerRow.insertBefore(selectAllCell, headerRow.firstChild);
			}

			var rowCheckboxes = [];
			for(var rowIndex = 0; rowIndex < bodyRows.length; rowIndex++) {
				var hostnameCell = bodyRows[rowIndex].querySelector('td:first-child');
				if(!hostnameCell) {
					continue;
				}

				var cell = document.createElement('td');
				var checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.name = 'servers[]';
				checkbox.value = hostnameCell.textContent.trim();
				cell.appendChild(checkbox);
				bodyRows[rowIndex].insertBefore(cell, bodyRows[rowIndex].firstChild);
				rowCheckboxes.push(checkbox);
			}

			selectAllCheckbox.addEventListener('click', function() {
				for(var checkboxIndex = 0; checkboxIndex < rowCheckboxes.length; checkboxIndex++) {
					rowCheckboxes[checkboxIndex].checked = this.checked;
				}
			});

			var reassignWrapper = document.createElement('div');
			reassignWrapper.className = 'form-group';
			var label = document.createElement('label');
			label.textContent = 'Reassign to ';
			var input = document.createElement('input');
			input.type = 'text';
			input.name = 'reassign_to';
			input.className = 'form-control';
			label.appendChild(input);
			reassignWrapper.appendChild(label);
			parent.appendChild(reassignWrapper);

			var submitWrapper = document.createElement('div');
			submitWrapper.className = 'form-group';
			var submitButton = document.createElement('button');
			submitButton.type = 'submit';
			submitButton.name = 'reassign_servers';
			submitButton.className = 'btn btn-primary';
			submitButton.textContent = 'Reassign selected servers';
			submitWrapper.appendChild(submitButton);
			parent.appendChild(submitWrapper);

			this.remove();
		});
	}
});

function set_status_text(element, classname, message) {
	if(!element) {
		return;
	}

	element.classList.remove('text-success', 'text-warning', 'text-danger', 'text-info');
	element.classList.add('text-' + classname);
	element.textContent = message;
}

function map_sync_status(syncStatus) {
	switch(syncStatus) {
	case 'sync success':
		return {classname: 'success', message: 'Synced'};
	case 'sync failure':
		return {classname: 'danger', message: 'Failed'};
	case 'sync warning':
		return {classname: 'warning', message: 'Not synced'};
	case 'proposed':
		return {classname: 'info', message: 'Requested'};
	default:
		return {classname: 'warning', message: 'Pending'};
	}
}

function fetch_json(url) {
	return fetch(url, {
		headers: {'Accept': 'application/json'}
	}).then(function(response) {
		if(!response.ok) {
			throw new Error('Request failed: ' + response.status);
		}
		return response.json();
	});
}

// Server sync status
dom_ready(function() {
	var statusDiv = document.getElementById('server_sync_status');
	if(!statusDiv) {
		return;
	}

	var statusSpan = statusDiv.querySelector('span');
	var explainLink = statusDiv.querySelector('a');
	var spinner = statusDiv.querySelector('div.spinner');
	var syncButton = statusDiv.querySelector('button[name="sync"]');
	var accountStatusSpans = document.querySelectorAll('span.server_account_sync_status');
	var timeout = 1000;
	var maxTimeout = 10000;

	function update_server_sync_status(classname, message) {
		set_status_text(statusSpan, classname, message);
		if(explainLink) {
			if(classname === 'success') {
				explainLink.classList.add('d-none');
			} else {
				explainLink.classList.remove('d-none');
				if(classname === 'warning') {
					explainLink.href = '/help#sync_warning';
				}
				if(classname === 'danger') {
					explainLink.href = '/help#sync_error';
				}
			}
		}
		if(spinner && spinner.parentNode) {
			spinner.parentNode.removeChild(spinner);
			spinner = null;
		}
		if(syncButton) {
			syncButton.classList.remove('invisible');
		}
	}

	function update_server_account_sync_status_by_id(id, classname, message) {
		var element = document.getElementById(id);
		set_status_text(element, classname, message);
	}

	function set_pending_statuses() {
		set_status_text(statusSpan, 'warning', 'Pending');
		for(var i = 0; i < accountStatusSpans.length; i++) {
			set_status_text(accountStatusSpans[i], 'warning', 'Pending');
		}
	}

	function poll_server_sync_status() {
		fetch_json(window.location.pathname + '/sync_status')
			.then(function(status) {
				if(status.pending) {
					timeout = Math.min(timeout * 1.5, maxTimeout);
					setTimeout(poll_server_sync_status, timeout);
				} else {
					var mappedServerStatus = map_sync_status(status.sync_status);
					update_server_sync_status(mappedServerStatus.classname, status.last_sync.details);
				}

				for(var i = 0; i < status.accounts.length; i++) {
					if(!status.accounts[i].pending) {
						var mappedAccountStatus = map_sync_status(status.accounts[i].sync_status);
						update_server_account_sync_status_by_id('server_account_sync_status_' + status.accounts[i].name, mappedAccountStatus.classname, mappedAccountStatus.message);
					}
				}
			})
			.catch(function() {
				timeout = Math.min(timeout * 1.5, maxTimeout);
				setTimeout(poll_server_sync_status, timeout);
			});
	}

	if(statusDiv.getAttribute('data-class')) {
		update_server_sync_status(statusDiv.getAttribute('data-class'), statusDiv.getAttribute('data-message'));
		for(var i = 0; i < accountStatusSpans.length; i++) {
			if(accountStatusSpans[i].getAttribute('data-class')) {
				update_server_account_sync_status_by_id(
					accountStatusSpans[i].id,
					accountStatusSpans[i].getAttribute('data-class'),
					accountStatusSpans[i].getAttribute('data-message')
				);
			}
		}
	} else {
		set_pending_statuses();
		poll_server_sync_status();
	}
});

// Server account sync status
dom_ready(function() {
	var statusDiv = document.getElementById('server_account_sync_status');
	if(!statusDiv) {
		return;
	}

	var statusSpan = statusDiv.querySelector('span');
	var spinner = statusDiv.querySelector('div.spinner');
	var timeout = 1000;
	var maxTimeout = 10000;

	function update_server_account_sync_status(classname, message) {
		set_status_text(statusSpan, classname, message);
		if(spinner && spinner.parentNode) {
			spinner.parentNode.removeChild(spinner);
			spinner = null;
		}
	}

	function poll_server_account_sync_status() {
		fetch_json(window.location.pathname + '/sync_status')
			.then(function(status) {
				if(status.pending) {
					timeout = Math.min(timeout * 1.5, maxTimeout);
					setTimeout(poll_server_account_sync_status, timeout);
				} else {
					var mappedStatus = map_sync_status(status.sync_status);
					update_server_account_sync_status(mappedStatus.classname, mappedStatus.message);
				}
			})
			.catch(function() {
				timeout = Math.min(timeout * 1.5, maxTimeout);
				setTimeout(poll_server_account_sync_status, timeout);
			});
	}

	if(statusDiv.getAttribute('data-class')) {
		update_server_account_sync_status(statusDiv.getAttribute('data-class'), statusDiv.getAttribute('data-message'));
	} else {
		update_server_account_sync_status('warning', 'Pending');
		poll_server_account_sync_status();
	}
});

// Server add form - multiple leader autocomplete
dom_ready(function() {
	var serverAdmin = document.getElementById('server_admin');
	var serverAdmins = document.getElementById('server_admins');
	if(!serverAdmin || !serverAdmins) {
		return;
	}

	function append_admin() {
		var newAdmin = serverAdmin.value.trim();
		if(!newAdmin) {
			return;
		}

		if(serverAdmins.value) {
			serverAdmins.value = serverAdmins.value + ', ' + newAdmin;
		} else {
			serverAdmins.value = newAdmin;
		}

		serverAdmin.value = '';
		serverAdmins.classList.remove('d-none');
		serverAdmin.removeAttribute('required');
	}

	serverAdmin.addEventListener('keydown', function(event) {
		if((event.key === 'Enter' || event.key === ' ' || event.key === ',') && serverAdmin.value.trim() !== '') {
			event.preventDefault();
			append_admin();
			serverAdmin.blur();
			serverAdmin.focus();
		}
	});

	serverAdmin.addEventListener('blur', function() {
		if(serverAdmin.value.trim()) {
			append_admin();
		}
	});

	serverAdmins.addEventListener('blur', function() {
		if(!serverAdmins.value.trim()) {
			serverAdmins.classList.add('d-none');
			serverAdmin.setAttribute('required', '');
		}
	});

	if(serverAdmins.value.trim()) {
		serverAdmins.classList.remove('d-none');
		serverAdmin.removeAttribute('required');
	}
});

// ldap tree view
function requestOU(guid, callback) {
	// fetch('/groups?' + new URLSearchParams({ //absolute vs relative path. change to absolute if ever needed.
	fetch('groups?' + new URLSearchParams({
		get_ldap_groups: "",
		guid,
	}))
		.then(response => response.json())
		.then(callback)
		.catch(() => {
			callback(null);
		});
}
function registerClickHandler(li, link, icon, guid) {
	let ul = null;
	link.addEventListener("click", (event) => {
		event.preventDefault();
		if (ul == null) {
			ul = genUL(guid);
			li.appendChild(ul);
			icon.classList.remove("ska-icon-folder-close");
			icon.classList.add("ska-icon-folder-open");
		} else {
			icon.classList.remove("ska-icon-folder-open");
			icon.classList.add("ska-icon-folder-close");
			li.removeChild(ul);
			ul = null;
		}
	});
}
function addOU(li, ou) {
	let link = document.createElement("a");
	link.setAttribute("href", "#");
	let icon = document.createElement("i");
	icon.classList.add("ska-icon");
	icon.classList.add("ska-icon-folder-close");
	link.appendChild(icon);
	let text = document.createTextNode(" " + ou.name);
	link.appendChild(text);
	li.appendChild(link);
	registerClickHandler(li, link, icon, ou.guid);
}
function addGroup(li, group) {
	let input = document.createElement("input");
	input.setAttribute("type", "checkbox");
	input.setAttribute("name", "groups[]");
	input.setAttribute("value", group.guid);
	input.id = group.guid;
	li.appendChild(input);
	let text = document.createTextNode(" " + group.name);
	let label = document.createElement("label");
	label.appendChild(text);
	label.setAttribute("for", group.guid);
	li.appendChild(label);
}
function genUL(guid) {
	let li = document.createElement("li");
	li.textContent = "Loading ...";
	let ul = document.createElement("ul");
	ul.appendChild(li);
	requestOU(guid, (result) => {
		if (result == null) {
			li.textContent = "(Error while loading groups)";
			return;
		}
		ul.removeChild(li);
		result.forEach((row) => {
			let rowLi = document.createElement("li");
			if (row.type == "ou") {
				addOU(rowLi, row);
			} else if (row.type == "group") {
				addGroup(rowLi, row);
			}
			ul.appendChild(rowLi);
		});
		if (result.length == 0) {
			let emptyLi = document.createElement("li");
			emptyLi.textContent = "(empty)";
			ul.appendChild(emptyLi);
		}
	});
	return ul;
}
function createTreeview(elem) {
	elem.innerHTML = "";
	elem.appendChild(genUL(null));
}
dom_ready(function() {
	let trees = document.querySelectorAll('div.ldap-treeview');
	for(let i = 0; i < trees.length; i++) {
		createTreeview(trees[i]);
	}
});

// Manage "select-all" checkbox on /servers
dom_ready(function() {
	let select_all = document.getElementById('cb_all_servers');
	if(!select_all) {
		return;
	}

	let host_selects = document.querySelectorAll('[name="selected_servers[]"]');
	select_all.addEventListener("input", () => {
		for(let i = 0; i < host_selects.length; i++) {
			host_selects[i].checked = select_all.checked;
		}
	});

	function update_select_all_box() {
		let all_checked = host_selects.length > 0;
		for(let i = 0; i < host_selects.length; i++) {
			all_checked = all_checked && host_selects[i].checked;
		}
		select_all.checked = all_checked;
	}

	for(let i = 0; i < host_selects.length; i++) {
		host_selects[i].addEventListener("input", update_select_all_box);
	}

	update_select_all_box();
});
