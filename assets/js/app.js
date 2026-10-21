(() => {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const escapeText = (value) => String(value ?? '');
  const badgeClass = (status) => ({
    Waiting: 'text-bg-primary',
    Called: 'text-bg-warning',
    Serving: 'text-bg-info',
    Completed: 'text-bg-success',
    Cancelled: 'text-bg-secondary',
    'No-show': 'text-bg-danger'
  }[status] || 'text-bg-light');

  async function request(url, data) {
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch' },
      body: data
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.error || 'The request could not be completed.');
    return body;
  }

  function showMessage(target, message, ok) {
    if (!target) return;
    target.textContent = message;
    target.className = `alert ${ok ? 'alert-success' : 'alert-danger'} mt-3 mb-0`;
  }

  const officeSelect = document.getElementById('officeSelect');
  const serviceSelect = document.getElementById('serviceSelect');
  if (officeSelect && serviceSelect) {
    const updateServices = () => {
      serviceSelect.replaceChildren(new Option('Choose a service', ''));
      const option = officeSelect.selectedOptions[0];
      serviceSelect.disabled = !option || !option.value || option.dataset.accepting !== '1';
      if (!option || !option.value) return;
      let services = [];
      try {
        services = JSON.parse(option.dataset.services || '[]');
      } catch {
        serviceSelect.add(new Option('Services could not be loaded', ''));
        return;
      }
      if (!services.length) {
        serviceSelect.add(new Option('No active services available', ''));
        serviceSelect.disabled = true;
      }
      services.forEach((service) => serviceSelect.add(new Option(service.name, service.id)));
    };
    officeSelect.addEventListener('change', updateServices);
    if (officeSelect.value) updateServices();
  }

  const joinForm = document.getElementById('joinQueueForm');
  joinForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = document.getElementById('joinButton');
    const message = document.getElementById('joinMessage');
    button.disabled = true;
    try {
      const result = await request('/CTUQueueManagement/api/join.php', new FormData(joinForm));
      showMessage(message, result.message, true);
      joinForm.reset();
      serviceSelect.replaceChildren(new Option('Choose a service', ''));
      serviceSelect.disabled = true;
      await refreshStudentQueue();
    } catch (error) {
      showMessage(message, error.message, false);
    } finally {
      button.disabled = false;
    }
  });

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = escapeText(text);
    return node;
  }

  function renderStudentTickets(data) {
    const root = document.getElementById('activeTickets');
    if (!root) return;
    root.replaceChildren();
    if (!data.tickets?.length) {
      const empty = element('div', 'col-12 text-secondary', 'You do not have active tickets. Choose an office above to join a queue.');
      root.append(empty);
    }
    data.tickets?.forEach((ticket) => {
      const col = element('div', 'col-md-6');
      const card = element('article', 'ticket-card h-100');
      const heading = element('div', 'd-flex justify-content-between align-items-start');
      const left = document.createElement('div');
      left.append(element('h3', 'h6 fw-bold mb-1', ticket.office_name));
      left.append(element('div', 'small text-secondary', ticket.service_name));
      heading.append(left);
      heading.append(element('span', `badge ${badgeClass(ticket.status)}`, ticket.status));
      card.append(heading);
      card.append(element('div', 'queue-number my-3', ticket.queue_label));
      const details = element('div', 'small text-secondary');
      details.append(element('div', '', `Currently serving/called: ${ticket.current_number ? `${ticket.office_name.slice(0, 2).toUpperCase()}-${String(ticket.current_number).padStart(3, '0')}` : 'Not called yet'}`));
      details.append(element('div', '', `People ahead: ${ticket.people_ahead}`));
      details.append(element('div', '', `Queue date: ${ticket.service_date}`));
      details.append(element('div', '', ticket.estimated_minutes === null ? 'Wait estimate unavailable' : `Estimated wait: about ${ticket.estimated_minutes} min`));
      card.append(details);
      if (ticket.status === 'Waiting' && Number(ticket.people_ahead) <= 2) {
        card.append(element('p', 'alert alert-info mt-3 mb-0 py-2', 'You are almost at the front of the queue. Please stay nearby.'));
      }
      if (ticket.status === 'Waiting') {
        const cancel = element('button', 'btn btn-sm btn-outline-danger mt-3', 'Cancel ticket');
        cancel.type = 'button';
        cancel.addEventListener('click', async () => {
          if (!window.confirm('Cancel this queue ticket?')) return;
          const form = new FormData();
          form.set('ticket_id', ticket.id);
          try {
            const result = await request('/CTUQueueManagement/api/cancel.php', form);
            showMessage(document.getElementById('joinMessage'), result.message, true);
            await refreshStudentQueue();
          } catch (error) {
            showMessage(document.getElementById('joinMessage'), error.message, false);
          }
        });
        card.append(cancel);
      }
      col.append(card);
      root.append(col);
    });
    const list = document.querySelector('.notification-list');
    if (list && data.notifications) {
      list.replaceChildren();
      if (!data.notifications.length) list.append(element('li', 'text-secondary', 'Queue updates will appear here.'));
      data.notifications.forEach((note) => {
        const item = element('li', '');
        item.append(element('p', 'mb-1', note.message));
        item.append(element('span', 'small text-secondary', note.created_at));
        list.append(item);
      });
    }
  }

  async function refreshStudentQueue() {
    const root = document.getElementById('activeTickets');
    if (!root) return;
    try {
      const response = await fetch('/CTUQueueManagement/api/queue.php', { headers: { 'X-Requested-With': 'fetch' } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Queue is unavailable.');
      renderStudentTickets(data);
    } catch (error) {
      root.replaceChildren(element('div', 'col-12 text-danger', error.message));
    }
  }
  if (document.querySelector('[data-queue-role="student"]')) {
    refreshStudentQueue();
    window.setInterval(refreshStudentQueue, 15000);
  }

  const staffQueue = document.getElementById('staffQueue');
  async function refreshStaffQueue() {
    if (!staffQueue) return;
    try {
      const query = new URLSearchParams({
        office: staffQueue.dataset.officeId,
        date: staffQueue.dataset.date
      });
      if (Number(staffQueue.dataset.serviceId)) query.set('service', staffQueue.dataset.serviceId);
      const response = await fetch(`/CTUQueueManagement/api/queue.php?${query.toString()}`, { headers: { 'X-Requested-With': 'fetch' } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Queue is unavailable.');
      staffQueue.replaceChildren();
      if (!data.tickets?.length) {
        const row = element('tr');
        row.append(element('td', 'text-center text-secondary py-4', 'No active tickets match these filters.'));
        row.firstChild.colSpan = 5;
        staffQueue.append(row);
      }
      data.tickets?.forEach((ticket) => {
        const row = element('tr');
        row.append(element('td', 'fw-bold', `${data.office.name.slice(0, 2).toUpperCase()}-${String(ticket.queue_number).padStart(3, '0')}`));
        row.append(element('td', '', ticket.full_name));
        row.append(element('td', '', ticket.service_name));
        const statusCell = element('td');
        statusCell.append(element('span', `badge ${badgeClass(ticket.status)}`, ticket.status));
        row.append(statusCell);
        row.append(element('td', 'small text-secondary', ticket.created_at));
        staffQueue.append(row);
      });
      const status = document.getElementById('officeStatus');
      const pause = document.querySelector('[data-staff-action="pause"], [data-staff-action="resume"]');
      if (status) status.textContent = Number(data.office.accepting_queue) ? 'Accepting tickets' : 'Queue paused';
      if (pause) {
        pause.dataset.staffAction = Number(data.office.accepting_queue) ? 'pause' : 'resume';
        pause.textContent = Number(data.office.accepting_queue) ? 'Pause queue' : 'Resume queue';
      }
    } catch (error) {
      staffQueue.replaceChildren(element('tr'));
      const cell = element('td', 'text-danger', error.message);
      cell.colSpan = 5;
      staffQueue.firstChild.append(cell);
    }
  }
  if (staffQueue) {
    refreshStaffQueue();
    window.setInterval(refreshStaffQueue, 15000);
  }

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-staff-action]');
    if (!button) return;
    button.disabled = true;
    const form = new FormData();
    form.set('action', button.dataset.staffAction);
    form.set('office_id', button.dataset.officeId);
    if (button.dataset.ticketId) form.set('ticket_id', button.dataset.ticketId);
    if (button.dataset.serviceId) form.set('service_id', button.dataset.serviceId);
    try {
      const result = await request('/CTUQueueManagement/api/staff-action.php', form);
      showMessage(document.getElementById('staffMessage'), result.message, true);
      if (button.dataset.staffAction === 'pause' || button.dataset.staffAction === 'resume') {
        button.dataset.staffAction = button.dataset.staffAction === 'pause' ? 'resume' : 'pause';
        button.textContent = button.dataset.staffAction === 'resume' ? 'Resume queue' : 'Pause queue';
      } else {
        window.setTimeout(() => window.location.reload(), 500);
      }
      refreshStaffQueue();
    } catch (error) {
      showMessage(document.getElementById('staffMessage'), error.message, false);
    } finally {
      button.disabled = false;
    }
  });

  document.querySelectorAll('[data-admin-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"], button:not([type])');
      if (button) button.disabled = true;
      const data = new FormData(form);
      data.set('action', form.dataset.adminForm);
      try {
        const result = await request('/CTUQueueManagement/api/admin-action.php', data);
        showMessage(document.getElementById('adminMessage'), result.message, true);
        form.reset();
      } catch (error) {
        showMessage(document.getElementById('adminMessage'), error.message, false);
      } finally {
        if (button) button.disabled = false;
      }
    });
  });

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-admin-action]');
    if (!button) return;
    if (!window.confirm('Apply this change?')) return;
    const form = new FormData();
    form.set('action', button.dataset.adminAction);
    ['userId', 'officeId', 'serviceId'].forEach((attribute) => {
      if (button.dataset[attribute]) form.set(attribute.replace(/[A-Z]/g, (letter) => `_${letter.toLowerCase()}`), button.dataset[attribute]);
    });
    button.disabled = true;
    try {
      const result = await request('/CTUQueueManagement/api/admin-action.php', form);
      showMessage(document.getElementById('adminMessage'), result.message, true);
      window.setTimeout(() => window.location.reload(), 700);
    } catch (error) {
      showMessage(document.getElementById('adminMessage'), error.message, false);
      button.disabled = false;
    }
  });
})();
