import app from 'flarum/common/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';

import FramePicker from './FramePicker';
import { frameOf } from '../frame';

const t = (key, params) => app.translator.trans(`ernestdefoe-backdrop.lib.editor.${key}`, params);

/**
 * Choose, frame and remove a cover. Used for a member's cover on the forum, and
 * in the admin for the default cover and for framing gallery covers; the caller
 * supplies the requests and decides what is offered.
 *
 * Attrs:
 * - title
 * - cover          the current {url, x, y, zoom, preset?}, or null
 * - canUpload      offer "upload your own" (default true)
 * - loadPresets()  → Promise of the gallery; omit to offer no gallery
 * - upload(FormData), choose({preset, x, y, zoom}), reframe({x, y, zoom}), remove()
 *                  → Promise; remove may be omitted
 */
export default class CoverModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);

    this.cover = this.attrs.cover || null;
    this.frame = frameOf(this.cover);
    this.file = null;
    this.preview = null;
    this.preset = null;
    this.presets = null;

    if (this.attrs.loadPresets) {
      this.attrs.loadPresets().then((presets) => {
        this.presets = presets;
        m.redraw();
      });
    }
  }

  className() {
    return 'BackdropModal Modal--large';
  }

  title() {
    return this.attrs.title;
  }

  onremove(vnode) {
    super.onremove(vnode);

    if (this.preview) URL.revokeObjectURL(this.preview);
  }

  canUpload() {
    return this.attrs.canUpload !== false;
  }

  // The gallery cover on screen: one just picked, or the one already in use.
  selectedPreset() {
    return this.preset?.id || (!this.file && this.cover?.preset) || null;
  }

  content() {
    const url = this.preview || this.preset?.url || this.cover?.url;
    const overlay = app.forum.attribute('backdrop')?.overlay || 'gradient';

    return (
      <div className="Modal-body">
        {url ? (
          <FramePicker url={url} frame={this.frame} overlay={overlay} onchange={(frame) => (this.frame = frame)} />
        ) : this.canUpload() ? (
          <button type="button" className="BackdropModal-empty" onclick={() => this.pick()}>
            {t('empty')}
          </button>
        ) : null}

        {url ? <p className="helpText">{t('hint')}</p> : this.canUpload() && <p className="helpText">{t('formats')}</p>}

        {this.gallery()}

        <input
          type="file"
          accept="image/jpeg,image/png,image/gif,image/webp"
          hidden
          onchange={(e) => {
            this.choose(e.target.files[0]);
            // Cleared, so choosing the same file again still fires a change.
            e.target.value = '';
          }}
        />

        <div className="BackdropModal-actions">
          {this.canUpload() && (
            <Button className="Button" icon="fas fa-upload" onclick={() => this.pick()} disabled={this.loading}>
              {url ? t('upload_own') : t('choose')}
            </Button>
          )}
          {this.cover && this.attrs.remove && !this.file && !this.preset && (
            <Button
              className="Button Button--danger"
              icon="fas fa-trash-alt"
              onclick={() => this.run(() => this.attrs.remove())}
              disabled={this.loading}
            >
              {t('remove')}
            </Button>
          )}
          <Button className="Button Button--primary" onclick={() => this.save()} loading={this.loading} disabled={!url}>
            {t('save')}
          </Button>
        </div>
      </div>
    );
  }

  gallery() {
    if (!this.attrs.loadPresets) return null;
    if (!this.presets) return <LoadingIndicator size="small" display="block" />;
    if (!this.presets.length) return null;

    const selected = this.selectedPreset();

    return (
      <div className="BackdropGallery">
        <h4 className="BackdropGallery-title">{t('gallery')}</h4>
        <div className="BackdropGallery-grid" role="listbox" aria-label={t('gallery_label')}>
          {this.presets.map((preset) => (
            <button
              key={preset.id}
              type="button"
              role="option"
              aria-selected={preset.id === selected ? 'true' : 'false'}
              className={classList('BackdropGallery-item', preset.id === selected && 'is-selected')}
              style={{ backgroundImage: `url("${preset.small}")`, backgroundPosition: `${preset.x}% ${preset.y}%` }}
              onclick={() => this.select(preset)}
            />
          ))}
        </div>
      </div>
    );
  }

  pick() {
    this.$('input[type=file]')[0].click();
  }

  select(preset) {
    this.clearFile();
    // Re-selecting the cover already in use keeps the member's own framing.
    this.preset = preset.id === this.cover?.preset ? null : preset;
    this.frame = frameOf(this.preset || this.cover);
  }

  choose(file) {
    if (!file) return;

    this.clearFile();
    this.preset = null;
    this.file = file;
    this.preview = URL.createObjectURL(file);
    this.frame = frameOf(null);
    m.redraw();
  }

  clearFile() {
    if (this.preview) URL.revokeObjectURL(this.preview);

    this.file = null;
    this.preview = null;
  }

  save() {
    if (this.preset) return this.run(() => this.attrs.choose({ preset: this.preset.id, ...this.frame }));
    if (!this.file) return this.run(() => this.attrs.reframe(this.frame));

    const body = new FormData();
    body.append('cover', this.file);
    Object.entries(this.frame).forEach(([key, value]) => body.append(key, String(value)));

    this.run(() => this.attrs.upload(body));
  }

  run(request) {
    this.loading = true;
    m.redraw();

    request().then(
      () => this.hide(),
      (error) => {
        this.loaded();
        throw error;
      }
    );
  }
}
