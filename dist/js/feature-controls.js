(() => {
  'use strict';
  const root = document.querySelector('.feature-controls');
  if (!root) return;
  let state = JSON.parse(document.getElementById('feature-controls-state').textContent);
  let role = null;
  let view = 'features';
  let busy = false;
  const rows = document.getElementById('feature-rows');
  const search = document.getElementById('feature-search');
  const rolePicker = document.getElementById('feature-role');
  const message = document.getElementById('feature-controls-message');
  const save = document.getElementById('feature-save');
  const endpoint = root.dataset.endpoint;
  const workspace = {
    'purchasing.approvals': 'purchasing.orders', 'purchasing.receipts.legacy': 'purchasing.orders',
    'purchasing.replenishment': 'purchasing.orders', 'purchasing.policy': 'purchasing.orders',
    'accounting.journals': 'accounting.workspace', 'accounting.sources': 'accounting.workspace',
    'accounting.chart_of_accounts': 'accounting.workspace', 'accounting.periods': 'accounting.workspace',
    'accounting.financial_reports': 'accounting.workspace', 'accounting.audit': 'accounting.workspace',
    'activities.calendar': 'activities.list', 'expenses.pay': 'expenses.records',
    'inventory.location_stock': '@inventory', 'inventory.locations': '@inventory',
    'inventory.transfers': '@inventory', 'inventory.packaging': '@inventory',
    'app.logout': '@app', 'app.global_search': '@app', 'app.quick_create': '@app'
  };
  let openBeforeSearch = null;

  function notice(text, error = false) {
    message.className = text ? 'alert ' + (error ? 'alert-danger' : 'alert-success') : '';
    message.textContent = text;
  }
  function request(action, data, method = 'GET') {
    return $.ajax({ url: endpoint + '?f=' + encodeURIComponent(action), method, data, dataType: 'json' });
  }
  function failure(xhr) { notice(xhr.responseJSON?.msg || 'The request failed. Please try again.', true); }
  function changed() { return [...rows.querySelectorAll('input[data-original]')].some(input => input.checked !== (input.dataset.original === '1')); }
  function leaveChanges() { return !changed() || window.confirm('Discard unsaved changes?'); }
  function addCell(row, tag, text) {
    const cell = document.createElement(tag);
    if (text !== undefined) cell.textContent = text;
    row.appendChild(cell);
    return cell;
  }
  function checkbox(feature, action, checked, label, container) {
    const wrapper = document.createElement('label');
    const input = document.createElement('input');
    input.type = 'checkbox'; input.checked = checked;
    input.dataset.original = checked ? '1' : '0'; input.dataset.feature = feature;
    if (action) input.dataset.action = action;
    wrapper.append(input, document.createTextNode(label)); container.appendChild(wrapper);
  }
  function groupFor(key) {
    let current = key;
    while (current) {
      if (workspace[current]) return workspace[current];
      const feature = state.features[current];
      if (!feature.parent) return current;
      current = feature.parent;
    }
    return key;
  }
  function filter() {
    const term = search.value.trim().toLowerCase();
    if (term && openBeforeSearch === null) openBeforeSearch = new Set([...rows.querySelectorAll('details[open]')].map(panel => panel.dataset.group));
    let matches = 0;
    rows.querySelectorAll('details').forEach(panel => {
      const groupMatches = panel.dataset.search.includes(term);
      let count = 0;
      panel.querySelectorAll('tbody tr').forEach(row => {
        const visible = !term || groupMatches || row.dataset.search.includes(term);
        row.hidden = !visible;
        if (visible) count++;
      });
      panel.hidden = count === 0;
      if (count) matches++;
      if (term && count) panel.open = true;
      if (!term && openBeforeSearch !== null) panel.open = openBeforeSearch.has(panel.dataset.group);
    });
    if (!term) openBeforeSearch = null;
    document.getElementById('feature-empty').hidden = matches !== 0;
  }
  function render() {
    const open = new Set([...rows.querySelectorAll('details[open]')].map(panel => panel.dataset.group));
    rows.replaceChildren();
    const groups = new Map();
    for (const [key, feature] of Object.entries(state.features)) {
      const group = groupFor(key);
      if (!groups.has(group)) {
        const panel = document.createElement('details'); panel.className = 'feature-panel'; panel.dataset.group = group;
        const groupLabel = state.features[group]?.label || (group === '@inventory' ? 'Inventory Workspace' : 'Application');
        panel.dataset.search = (groupLabel + ' ' + group).toLowerCase(); panel.open = open.has(group);
        const heading = document.createElement('summary');
        const title = document.createElement('span'); title.textContent = groupLabel;
        const icon = document.createElement('i'); icon.className = 'fas fa-chevron-down'; icon.setAttribute('aria-hidden', 'true');
        heading.append(title, icon); panel.appendChild(heading);
        const wrapper = document.createElement('div'); wrapper.className = 'table-responsive';
        const table = document.createElement('table'); table.className = 'table table-sm feature-table';
        const head = document.createElement('thead'); const header = document.createElement('tr');
        addCell(header, 'th', 'Feature').scope = 'col'; addCell(header, 'th', view === 'roles' ? 'Allowed actions' : 'Available').scope = 'col';
        head.appendChild(header); table.appendChild(head);
        const body = document.createElement('tbody'); table.appendChild(body); wrapper.appendChild(table); panel.appendChild(wrapper);
        rows.appendChild(panel); groups.set(group, body);
      }
      const row = document.createElement('tr');
      row.dataset.search = (feature.label + ' ' + key + ' ' + feature.scope).toLowerCase();
      const name = addCell(row, 'td', feature.label);
      const code = document.createElement('span'); code.className = 'feature-key'; code.textContent = key; name.appendChild(code);
      const actions = addCell(row, 'td'); actions.className = 'feature-actions';
      if (feature.protected) {
        const label = document.createElement('span'); label.className = 'feature-protected'; label.textContent = 'Always available'; actions.appendChild(label);
      } else if (view === 'features') {
        checkbox(key, null, !!state.flags[key], 'Enabled', actions);
      } else if (role) {
        for (const action of feature.actions) checkbox(key, action, !!role.permissions[key]?.[action], action.replaceAll('_', ' '), actions);
      }
      groups.get(group).appendChild(row);
    }
    filter();
  }
  function loadRole() {
    busy = true; save.disabled = true;
    request('role', { role_id: rolePicker.value }).done(data => { role = data.data; render(); }).fail(failure).always(() => { busy = false; save.disabled = false; });
  }
  root.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => {
    const target = button.dataset.view;
    if (target === view || busy || !leaveChanges()) return;
    view = target; notice('');
    root.querySelectorAll('[data-view]').forEach(tab => {
      const active = tab.dataset.view === view;
      tab.classList.toggle('btn-primary', active); tab.classList.toggle('btn-outline-primary', !active); tab.setAttribute('aria-pressed', String(active));
    });
    document.getElementById('feature-role-picker').hidden = view !== 'roles';
    if (view === 'roles') loadRole(); else render();
  }));
  let selectedRole = rolePicker.value;
  rolePicker.addEventListener('change', () => {
    if (busy || !leaveChanges()) { rolePicker.value = selectedRole; return; }
    selectedRole = rolePicker.value; notice(''); loadRole();
  });
  search.addEventListener('input', filter);
  document.getElementById('retail-roles').addEventListener('click', () => {
    if (busy || !leaveChanges()) return;
    if (!window.confirm('Create suggested retail roles? Existing roles will not be changed, and no users will be assigned.')) return;
    busy = true; save.disabled = true;
    request('create_retail_roles', {}, 'POST').done(response => {
      state = response.data.state;
      rolePicker.replaceChildren();
      state.roles.forEach(item => {
        const option = document.createElement('option'); option.value = item.id;
        option.textContent = item.name + (Number(item.active) ? '' : ' (inactive)');
        option.selected = String(item.id) === String(selectedRole); rolePicker.appendChild(option);
      });
      const result = response.data.result;
      notice((result.created.length ? 'Created: ' + result.created.join(', ') + '. ' : '') +
        (result.skipped.length ? 'Existing roles left unchanged: ' + result.skipped.join(', ') + '. ' : '') +
        (result.unavailable.length ? 'Disabled features were not granted: ' + result.unavailable.join(', ') + '. ' : '') +
        'No users were assigned. These settings do not enforce application access yet.');
      render();
    }).fail(failure).always(() => { busy = false; save.disabled = false; });
  });
  save.addEventListener('click', () => {
    if (busy) return;
    const edits = [...rows.querySelectorAll('input[data-original]')].filter(input => input.checked !== (input.dataset.original === '1'));
    if (!edits.length) { notice('There are no changes to save.'); return; }
    if (!window.confirm('Save ' + edits.length + ' ' + (view === 'features' ? 'feature' : 'permission') + ' change(s)?')) return;
    let data, action;
    if (view === 'features') {
      action = 'save_flags'; data = { version: state.version, changes: {} };
      edits.forEach(input => { data.changes[input.dataset.feature] = input.checked ? '1' : '0'; });
    } else {
      action = 'save_role_permissions'; data = { role_id: role.role.id, version: role.role.version, changes: edits.map(input => ({ feature: input.dataset.feature, action: input.dataset.action, allowed: input.checked ? '1' : '0' })) };
    }
    busy = true; save.disabled = true;
    request(action, data, 'POST').done(result => {
      if (view === 'features') state = result.data; else role = result.data;
      render(); notice('Changes saved in the test preview. Application access is not yet enforced.');
    }).fail(failure).always(() => { busy = false; save.disabled = false; });
  });
  window.addEventListener('beforeunload', event => { if (changed()) { event.preventDefault(); event.returnValue = ''; } });
  render();
})();
