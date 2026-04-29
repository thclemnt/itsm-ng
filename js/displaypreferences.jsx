import { h, render } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import { batch, useComputed, useSignal } from '@preact/signals';

(function (window, document) {
  const rootDoc = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
  const endpoint = rootDoc + '/ajax/v2/displaypreferences.php';

  let openCounter = 0;

  const fetchJson = (data) => {
    const formData = new FormData();
    const csrf = document.querySelector('meta[property="glpi:csrf_token"]');
    if (csrf && csrf.content) {
      formData.append('_glpi_csrf_token', csrf.content);
    }
    Object.entries(data).forEach(([key, value]) => {
      if (Array.isArray(value)) {
        value.forEach((entry) => formData.append(key + '[]', entry));
      } else if (value !== undefined && value !== null) {
        formData.append(key, value);
      }
    });

    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    }).then((res) => res.json());
  };

  const createModal = () => {
    let modal = document.getElementById('display-preferences-modal');
    if (modal) {
      return modal;
    }

    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
            <div class="modal fade" id="display-preferences-modal" tabindex="-1" aria-labelledby="display-preferences-title" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="display-preferences-title"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body overflow-x-hidden">
                            <div id="display-preferences-modal-content"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${window.__('Close')}</button>
                            <button type="button" class="btn btn-primary" id="display-preferences-save">${window.__('Save')}</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    document.body.appendChild(wrapper.firstElementChild);
    modal = document.getElementById('display-preferences-modal');
    return modal;
  };

  const groupAvailable = (available) => {
    const groups = new Map();
    available.forEach((item) => {
      const group = item.group || '';
      if (!groups.has(group)) {
        groups.set(group, []);
      }
      groups.get(group).push(item);
    });
    return Array.from(groups.entries());
  };

  const getSelectedList = (selected, locked) => {
    const normalized = Array.from(new Set(selected.map((id) => Number(id)).filter((id) => !Number.isNaN(id))));
    return [...locked, ...normalized.filter((id) => !locked.includes(id))];
  };

  const DisplayPreferencesApp = ({ itemtype, initialView, onSaved }) => {
    const loading = useSignal(true);
    const view = useSignal(initialView);
    const data = useSignal(null);
    const selected = useSignal([]);
    const available = useSignal([]);
    const locked = useSignal([]);
    const noremove = useSignal([]);
    const labels = useSignal({});
    const saving = useSignal(false);
    const message = useSignal(null);
    const showDeleteModal = useSignal(false);
    const selectedColumnToAdd = useSignal('');
    const initialLoad = useRef(true);
    const gridRef = useRef(null);
    const gridApi = useRef(null);

    const syncGridWidgets = () => {
      const currentData = data.value;
      if (!gridApi.current || !gridRef.current || !currentData) {
        return;
      }
      if (view.value === 'personal' && !currentData.has_personal) {
        return;
      }
      const items = Array.from(gridRef.current.querySelectorAll('.grid-stack-item'));

      gridApi.current.engine.nodes.slice().forEach((node) => {
        const id = node.el.getAttribute('data-column-id');
        if (!items.find((item) => item.getAttribute('data-column-id') === id)) {
          gridApi.current.removeWidget(node.el, false);
        }
      });

      items.forEach((item, index) => {
        if (!item.gridstackNode) {
          gridApi.current.makeWidget(item);
        }
        gridApi.current.update(item, { x: 0, y: index, w: 1, h: 1 });
      });

      const lockedSet = new Set(locked.value);
      items.forEach((item) => {
        const id = Number(item.getAttribute('data-column-id'));
        gridApi.current.movable(item, !lockedSet.has(id));
      });

      gridApi.current.compact();
    };

    const load = (targetView) => {
      const defaultIfNoPersonal = initialLoad.current;
      initialLoad.current = false;
      batch(() => {
        loading.value = true;
        message.value = null;
      });
      fetchJson({
        action: 'load',
        itemtype,
        view: targetView,
        default_if_no_personal: defaultIfNoPersonal ? 1 : 0
      })
        .then((result) => {
          if (!result.success) {
            message.value = { type: 'danger', text: result.message || 'Error' };
            return;
          }
          const effectiveView = result.view || targetView;
          const selectedList = getSelectedList(result.selected || [], result.locked || []);
          batch(() => {
            data.value = result;
            if (targetView !== effectiveView) {
              view.value = effectiveView;
            }
            selected.value = selectedList;
            available.value = result.available || [];
            locked.value = result.locked || [];
            noremove.value = result.noremove || [];
            labels.value = result.labels || {};
          });
        })
        .catch(() => {
          message.value = { type: 'danger', text: 'Unable to load preferences.' };
        })
        .finally(() => {
          loading.value = false;
        });
    };

    useEffect(() => {
      load(view.value);
    }, [view.value, itemtype]);

    useEffect(() => {
            const currentData = data.value;
            if (!gridRef.current || !window.GridStack || !currentData) {
                return;
            }
            const canRender = selected.value.length > 0 && !(view.value === 'personal' && !currentData.has_personal);
      if (!canRender) {
        if (gridApi.current) {
          gridApi.current.off('change');
          gridApi.current.destroy(false);
          gridApi.current = null;
        }
        return;
      }
      if (gridApi.current) {
        gridApi.current.off('change');
        gridApi.current.destroy(false);
        gridApi.current = null;
      }

            const onChange = () => {
                if (!gridApi.current || !gridApi.current.engine) {
                    return;
                }
                const nodes = gridApi.current.engine.nodes.slice().sort((a, b) => a.y - b.y);
        const order = nodes.map((node) => Number(node.el.getAttribute('data-column-id')));
        const normalized = order.filter((id) => !Number.isNaN(id));
        const lockedIds = locked.value.filter((id) => normalized.includes(id));
        const rest = normalized.filter((id) => !lockedIds.includes(id));
        const merged = [...lockedIds, ...rest];
                gridApi.current.compact('list');
                if (merged.length && merged.every((id, index) => selected.value[index] === id)) {
                    return;
                }
                selected.value = merged;
            };

            gridApi.current = window.GridStack.init({
                column: 1,
                cellHeight: 44,
                float: false,
                disableOneColumnMode: true,
                margin: 6,
                layout: 'list',
                draggable: {
                    handle: '.grid-stack-item-content',
                    appendTo: 'body',
                    scroll: true,
                },
                disableResize: true,
            }, gridRef.current);

            if (gridApi.current) {
                gridApi.current.on('change', onChange);
                requestAnimationFrame(syncGridWidgets);
            }

      return () => {
        if (gridApi.current) {
          gridApi.current.off('change', onChange);
          gridApi.current.destroy(false);
          gridApi.current = null;
        }
      };
    }, [data.value, selected.value.length, view.value, itemtype, locked.value]);

    useEffect(() => {
      syncGridWidgets();
    }, [selected.value, locked.value, noremove.value, view.value, itemtype, data.value]);

    const handleRemove = (id) => {
      if (locked.value.includes(id) || noremove.value.includes(id)) {
        return;
      }
      selected.value = selected.value.filter((entry) => entry !== id);
    };

    const handleAdd = () => {
      const value = Number(selectedColumnToAdd.value);
      if (!value) {
        return;
      }
      if (!selected.value.includes(value)) {
        selected.value = [...selected.value, value];
      }
      selectedColumnToAdd.value = '';
    };

    const handleSave = () => {
      if (view.value === 'personal' && data.value && !data.value.has_personal) {
        message.value = { type: 'danger', text: window.__('Create a personal view before saving.') };
        return;
      }
      batch(() => {
        saving.value = true;
        message.value = null;
      });
      fetchJson({
        action: 'save',
        itemtype,
        view: view.value,
        order: selected.value.filter((id) => !locked.value.includes(id))
      })
        .then((result) => {
          if (!result.success) {
            message.value = { type: 'danger', text: result.message || window.__('Error') };
            return;
          }
          message.value = { type: 'success', text: window.__('Saved') };
          if (typeof onSaved === 'function') {
            onSaved();
          }
        })
        .catch(() => {
          message.value = { type: 'danger', text: window.__('Unable to save preferences.') };
        })
        .finally(() => {
          saving.value = false;
        });
    };

    const handleActivatePersonal = () => {
      saving.value = true;
      fetchJson({ action: 'activate_personal', itemtype })
        .then((result) => {
          if (!result.success) {
            message.value = { type: 'danger', text: result.message || window.__('Error') };
            return;
          }
          message.value = { type: 'success', text: window.__('Personal view created') };
          load('personal');
        })
        .catch(() => {
          message.value = { type: 'danger', text: window.__('Unable to create personal view.') };
        })
        .finally(() => {
          saving.value = false;
        });
    };

    const handleDeletePersonal = () => {
      showDeleteModal.value = true;
    };

    const handleConfirmDelete = () => {
      batch(() => {
        showDeleteModal.value = false;
        saving.value = true;
      });
      fetchJson({ action: 'delete_personal', itemtype })
        .then((result) => {
          if (!result.success) {
            message.value = { type: 'danger', text: result.message || window.__('Error') };
            return;
          }
          message.value = { type: 'success', text: window.__('Personal view deleted') };
          load('global');
        })
        .catch(() => {
          message.value = { type: 'danger', text: window.__('Unable to delete personal view.') };
        })
        .finally(() => {
          saving.value = false;
        });
    };

    const addable = useComputed(() => available.value.filter((item) => !selected.value.includes(item.id)));
    const canAdd = addable.value.length > 0;

    if (!data.value) {
      return loading.value ? null : <div class="alert alert-danger">{window.__('Unable to load preferences')}</div>;
    }

    const showEditor = !(view.value === 'personal' && !data.value.has_personal);

    return (
      <div class="display-preferences">
        {data.value.can_personal || data.value.can_global ? (
          <div class="d-flex flex-wrap gap-2 mb-3">
            {data.value.can_personal ? (
              <button
                type="button"
                class={'btn btn-sm ' + (view.value === 'personal' ? 'btn-primary' : 'btn-outline-primary')}
                onClick={() => { view.value = 'personal'; }}
              >
                {window.__('Personal View')}
              </button>
            ) : ''}
            {data.value.can_global ? (
              <button
                type="button"
                class={'btn btn-sm ' + (view.value === 'global' ? 'btn-primary' : 'btn-outline-primary')}
                onClick={() => { view.value = 'global'; }}
              >
                {window.__('Global View')}
              </button>
            ) : ''}
            {view.value === 'personal' && data.value.has_personal ? (
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                onClick={handleDeletePersonal}
                disabled={saving.value}
              >
                {window.__('Delete personal view')}
              </button>
            ) : ''}
          </div>
        ) : ''}

        {view.value === 'personal' && !data.value.has_personal ? (
          <div class="alert alert-info">
            <div class="d-flex justify-content-between align-items-center">
              <div>{window.__('No personal criteria. Create personal parameters?')}</div>
              <button
                type="button"
                class="btn btn-sm btn-secondary"
                onClick={handleActivatePersonal}
                disabled={saving.value}
              >
                {window.__('Create')}
              </button>
            </div>
          </div>
        ) : ''}

        {message.value ? <div class={'alert alert-' + message.value.type}>{message.value.text}</div> : ''}

        {showEditor ? (
          <div>
            <div class="row">
              <div class="col-12">
                <div class="card no-shadow">
                  <div class="card-header">{window.__('Selected columns')}</div>
                  <div class="card-body">
                    <div class="grid-stack" ref={gridRef}>
                      {selected.value.map((id, index) => {
                        const label = labels.value[id] || id;
                        const isLocked = locked.value.includes(id);
                        const isNoRemove = noremove.value.includes(id);

                        return (
                          <div
                            key={id}
                            class="grid-stack-item"
                            data-column-id={id}
                            data-locked={isLocked}
                            gs-x="0"
                            gs-y={index}
                            gs-w="1"
                            gs-h="1"
                          >
                            <div class="grid-stack-item-content d-flex align-items-center justify-content-between">
                              <div class="d-flex align-items-center">
                                <i class="fas fa-grip-lines me-2"></i>
                                <span>{label}</span>
                              </div>
                              {isLocked ? (
                                <button type="button" class="btn btn-sm btn-link text-secondary" disabled>
                                  <i class="fas fa-lock"></i>
                                </button>
                              ) : (
                                isNoRemove ? (
                                  <span class="badge bg-secondary">{window.__('Required')}</span>
                                ) : (
                                  <button type="button" class="btn btn-sm btn-link text-danger" onClick={() => handleRemove(id)}>
                                    <i class="fas fa-times"></i>
                                  </button>
                                )
                              )}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                  <div class="card-footer">
                    <div class="row align-items-center g-2">
                      <div class="col-auto">
                        <select
                          class="form-select"
                          value={selectedColumnToAdd.value}
                          onChange={(e) => { selectedColumnToAdd.value = e.target.value; }}
                          disabled={!canAdd}
                        >
                          <option value="">{window.__('Choose a column')}</option>
                          {groupAvailable(addable.value).map(([group, items]) => {
                            if (!group) {
                              return items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>);
                            }
                            return (
                              <optgroup key={group} label={group}>
                                {items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                              </optgroup>
                            );
                          })}
                        </select>
                      </div>
                      <div class="col-auto">
                        <button
                          type="button"
                          class="btn btn-primary"
                          onClick={handleAdd}
                          disabled={!canAdd || !selectedColumnToAdd.value}
                        >
                          <i class="fas fa-plus"></i>
                        </button>
                      </div>
                    </div>
                    <div class="text-muted mt-2">
                      {window.__('Drag to reorder columns. Locked columns cannot be removed.')}
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="d-none" id="display-preferences-save-hook">
              <button type="button" onClick={handleSave} disabled={saving.value}>{window.__('Save')}</button>
            </div>
          </div>
        ) : ''}
        {showDeleteModal.value ? (
          <div class="modal fade show" style="display: block; background-color: rgba(0,0,0,0.5);" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">{window.__('Delete personal view')}</h5>
                  <button type="button" class="btn-close" onClick={() => { showDeleteModal.value = false; }} aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <p>{window.__('Are you sure you want to delete your personal view and revert to the global view?')}</p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" onClick={() => { showDeleteModal.value = false; }}>{window.__('Cancel')}</button>
                  <button type="button" class="btn btn-danger" onClick={handleConfirmDelete}>{window.__('Delete')}</button>
                </div>
              </div>
            </div>
          </div>
        ) : ''}
      </div>
    );
  };

  const renderModalContent = (itemtype, initialView, key) => {
    const content = document.getElementById('display-preferences-modal-content');
    if (!content) {
      return;
    }
    render(
      <DisplayPreferencesApp
        key={key}
        itemtype={itemtype}
        initialView={initialView}
        onSaved={() => window.location.reload()}
      />,
      content
    );
  };

  const openModal = (itemtype) => {
    if (!itemtype) {
      return;
    }
    const modal = createModal();
    const title = modal.querySelector('#display-preferences-title');
    if (title) {
      title.textContent = window.__('Select default items to show');
    }
    openCounter += 1;
    const renderKey = `${itemtype}-${openCounter}`;
    const instance = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modal) : null;
    if (instance) {
      modal.addEventListener('shown.bs.modal', () => {
        renderModalContent(itemtype, 'personal', renderKey);
      }, { once: true });
      instance.show();
    } else {
      modal.classList.add('show');
      modal.style.display = 'block';
      renderModalContent(itemtype, 'personal', renderKey);
    }

    const saveButton = document.getElementById('display-preferences-save');
    if (saveButton) {
      saveButton.onclick = () => {
        const hook = document.querySelector('#display-preferences-save-hook button');
        if (hook) {
          hook.click();
        }
      };
    }
  };

  window.DisplayPreferences = {
    open: openModal,
  };
})(window, document);
