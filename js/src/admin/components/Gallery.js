import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import CoverModal from '../../common/components/CoverModal';

const t = (key, params) => app.translator.trans(`ernestdefoe-backdrop.admin.${key}`, params);
const LIMIT = 24;

/**
 * The cover gallery. Like the default cover it saves as you go, through its
 * own endpoint; every response is the whole gallery, which replaces ours.
 */
export default class Gallery extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.presets = null;
    this.busy = false;
    this.request('GET');

    import('flarum/admin/utils/loadSortable').then(({ default: sortable }) => {
      this.sortable = sortable;
      this.makeSortable();
    });
  }

  onremove() {
    this.sortableInstance?.destroy();
  }

  view() {
    return (
      <div className="Form-group BackdropAdminGallery">
        <label>{t('gallery')}</label>
        <div className="helpText">{t('gallery_help', { max: LIMIT })}</div>

        {!this.presets ? (
          <LoadingIndicator size="small" display="block" />
        ) : (
          <>
            {!this.presets.length && <p className="BackdropAdminGallery-empty">{t('gallery_empty')}</p>}
            <ul className="BackdropAdminGallery-list" oncreate={(vnode) => ((this.list = vnode.dom), this.makeSortable())}>
              {this.presets.map((preset) => (
                <li key={preset.id} className="BackdropAdminGallery-item">
                  <div
                    className="BackdropAdminGallery-thumb"
                    style={{ backgroundImage: `url("${preset.small}")`, backgroundPosition: `${preset.x}% ${preset.y}%` }}
                  />
                  <div className="BackdropAdminGallery-actions">
                    <Button
                      className="Button Button--icon Button--flat"
                      icon="fas fa-crop-alt"
                      aria-label={t('gallery_frame')}
                      title={t('gallery_frame')}
                      onclick={() => this.frame(preset)}
                    />
                    <Button
                      className="Button Button--icon Button--flat"
                      icon="fas fa-trash-alt"
                      aria-label={t('gallery_delete')}
                      title={t('gallery_delete')}
                      onclick={() => this.remove(preset)}
                    />
                  </div>
                </li>
              ))}
            </ul>
          </>
        )}

        <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden onchange={(e) => this.add([...e.target.files])} />
        <Button
          className="Button"
          icon="fas fa-plus"
          loading={this.busy}
          disabled={!this.presets || this.presets.length >= LIMIT}
          onclick={() => this.$('input[type=file]')[0].click()}
        >
          {t('gallery_add')}
        </Button>
      </div>
    );
  }

  request(method, path = '', body) {
    return app
      .request({
        method,
        url: `${app.forum.attribute('apiUrl')}/backdrop/presets${path}`,
        body,
        serialize: body instanceof FormData ? (raw) => raw : undefined,
      })
      .then((response) => {
        this.presets = response.data;
        m.redraw();
      });
  }

  // One request per file, in the order chosen, stopping at the first refusal.
  async add(files) {
    this.$('input[type=file]')[0].value = '';
    this.busy = true;
    // No reordering until every upload is in, or an order sent mid-batch
    // would be built from a gallery that is about to change.
    this.sortableInstance?.option('disabled', true);

    try {
      for (const file of files.slice(0, LIMIT - this.presets.length)) {
        const body = new FormData();
        body.append('cover', file);
        await this.request('POST', '', body);
      }
    } finally {
      this.busy = false;
      this.sortableInstance?.option('disabled', false);
      m.redraw();
    }
  }

  frame(preset) {
    app.modal.show(CoverModal, {
      title: t('gallery_frame_title'),
      cover: preset,
      canUpload: false,
      reframe: (frame) => this.request('PATCH', `/${preset.id}`, frame),
    });
  }

  remove(preset) {
    if (confirm(t('gallery_delete_confirm'))) this.request('DELETE', `/${preset.id}`);
  }

  makeSortable() {
    if (!this.sortable || !this.list?.isConnected || this.sortableInstance) return;

    this.sortableInstance = this.sortable.create(this.list, {
      animation: 150,
      delay: 50,
      delayOnTouchOnly: true,
      ghostClass: 'BackdropAdminGallery-item--ghost',
      onEnd: ({ from, item, oldIndex, newIndex }) => {
        if (oldIndex === newIndex) return;

        // Put the DOM back where Mithril left it, then let the redraw move it.
        from.insertBefore(item, from.children[oldIndex + (oldIndex > newIndex ? 1 : 0)] || null);

        const order = this.presets.map((p) => p.id);
        order.splice(newIndex, 0, ...order.splice(oldIndex, 1));
        this.presets = order.map((id) => this.presets.find((p) => p.id === id));
        m.redraw();

        this.request('PUT', '', { order });
      },
    });
  }
}
