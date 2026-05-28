define(['view'], function (View) {
    return class NexusSettingsView extends View {

        templateContent = `
            <div class="page-header">
                <h3>
                    <span class="fas fa-brain" style="color:#2b7de9;margin-right:8px;"></span>
                    NEXUS Integration Settings
                </h3>
            </div>
            <div class="panel panel-default">
                <div class="panel-body">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="control-label">NEXUS URL</label>
                                <input type="text" class="form-control" name="nexusUrl"
                                    value="{{nexusUrl}}" placeholder="http://potpie.local:8000">
                            </div>
                            <div class="form-group">
                                <label class="control-label">Username</label>
                                <input type="text" class="form-control" name="nexusUsername"
                                    value="{{nexusUsername}}" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label class="control-label">Password</label>
                                <input type="password" class="form-control" name="nexusPassword"
                                    placeholder="Leave blank to keep existing" autocomplete="new-password">
                            </div>
                            <div class="form-group">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="nexusEnabled"
                                            {{#if nexusEnabled}}checked{{/if}}>
                                        Enable NEXUS Integration
                                    </label>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="nexusRagEnabled"
                                            {{#if nexusRagEnabled}}checked{{/if}}>
                                        Auto-ingest records into NEXUS RAG
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="nexus-status-bar" style="min-height:24px;margin-bottom:12px;font-size:13px;">
                        {{#if statusMsg}}{{{statusMsg}}}{{/if}}
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button class="btn btn-primary" data-action="save">Save</button>
                        <button class="btn btn-default" data-action="testConnection">
                            <span class="fas fa-plug" style="margin-right:4px;"></span>Test Connection
                        </button>
                    </div>
                </div>
            </div>
        `;

        events = {
            'click [data-action="save"]': 'onSave',
            'click [data-action="testConnection"]': 'onTest',
        };

        nexusSettings = {};
        statusMsg = '';

        data() {
            return {
                nexusUrl:        this.nexusSettings.nexusUrl        || 'http://potpie.local:8000',
                nexusUsername:   this.nexusSettings.nexusUsername   || '',
                nexusEnabled:    this.nexusSettings.nexusEnabled    || false,
                nexusRagEnabled: this.nexusSettings.nexusRagEnabled !== false,
                statusMsg:       this.statusMsg,
            };
        }

        setup() {
            this.nexusSettings = {};
            this.statusMsg = '';
            Espo.Ajax.getRequest('nexus/settings')
                .then(data => { this.nexusSettings = data; this.reRender(); })
                .catch(() => {
                    this.statusMsg = '<span class="fas fa-exclamation-triangle text-warning"></span> Could not load settings.';
                    this.reRender();
                });
        }

        onSave() {
            const $btn = this.$el.find('[data-action="save"]').prop('disabled', true);
            const payload = {
                nexusUrl:        this.$el.find('[name="nexusUrl"]').val().trim(),
                nexusUsername:   this.$el.find('[name="nexusUsername"]').val().trim(),
                nexusEnabled:    this.$el.find('[name="nexusEnabled"]').is(':checked'),
                nexusRagEnabled: this.$el.find('[name="nexusRagEnabled"]').is(':checked'),
            };
            const password = this.$el.find('[name="nexusPassword"]').val();
            if (password) payload.nexusPassword = password;

            Espo.Ajax.putRequest('nexus/settings', payload)
                .then(() => {
                    this.nexusSettings = Object.assign({}, this.nexusSettings, payload);
                    this._setStatus('<span class="fas fa-check-circle text-success"></span> Settings saved.');
                    Espo.Ui.notify('Saved', 'success', 2000);
                })
                .catch(() => this._setStatus('<span class="fas fa-times-circle text-danger"></span> Save failed.'))
                .finally(() => $btn.prop('disabled', false));
        }

        onTest() {
            this._setStatus('<span class="fas fa-spinner fa-spin"></span> Testing connection…');
            Espo.Ajax.getRequest('nexus/health')
                .then(data => {
                    if (data.healthy) {
                        this._setStatus('<span class="fas fa-check-circle text-success"></span> NEXUS reachable — version ' + (data.version || '') + ', ' + (data.serviceCount || '?') + ' services.');
                    } else {
                        this._setStatus('<span class="fas fa-times-circle text-danger"></span> NEXUS responded but reports unhealthy.');
                    }
                })
                .catch(() => this._setStatus('<span class="fas fa-times-circle text-danger"></span> Could not connect to NEXUS.'));
        }

        _setStatus(html) { this.$el.find('.nexus-status-bar').html(html); }
    };
});
