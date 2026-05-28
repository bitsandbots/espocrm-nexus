define(['view'], function (View) {
    return class NexusAssistantView extends View {

        templateContent = `
            <div class="nexus-assistant" style="padding:4px 0;">
                {{#unless nexusEnabled}}
                <div class="alert alert-warning" style="font-size:12px;padding:6px 10px;margin:0 0 8px;">
                    NEXUS disabled. <a href="#Admin/nexusSettings">Configure in Admin</a>.
                </div>
                {{/unless}}
                <textarea class="form-control nexus-prompt" rows="2"
                    placeholder="Ask NEXUS about this record… (Ctrl+Enter)"
                    style="resize:vertical;font-size:13px;margin-bottom:6px;"
                    {{#unless nexusEnabled}}disabled{{/unless}}></textarea>
                <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">
                    <button class="btn btn-default btn-sm" data-action="nexusAsk"
                        {{#unless nexusEnabled}}disabled{{/unless}}>
                        <span class="fas fa-brain" style="margin-right:4px;color:#2b7de9;"></span>Ask
                    </button>
                    <span class="nexus-loading" style="display:none;font-size:12px;color:#888;">
                        <span class="fas fa-spinner fa-spin"></span> Asking…
                    </span>
                </div>
                <div class="nexus-error text-danger" style="display:none;font-size:12px;margin-bottom:6px;"></div>
                <div class="nexus-result" style="display:none;">
                    <div class="nexus-result-meta" style="font-size:11px;color:#aaa;margin-bottom:3px;"></div>
                    <div class="nexus-result-text"
                        style="white-space:pre-wrap;font-size:12px;background:#f8f8f8;
                               padding:8px;border-radius:3px;border:1px solid #e0e0e0;
                               max-height:250px;overflow-y:auto;"></div>
                </div>
                <div class="nexus-queue-fallback" style="display:none;margin-top:6px;">
                    <small class="text-muted">
                        Timed out. <a href="#" data-action="nexusSubmitQueue">Queue as background job</a>
                    </small>
                </div>
            </div>
        `;

        events = {
            'click [data-action="nexusAsk"]':         'onAsk',
            'click [data-action="nexusSubmitQueue"]': 'onSubmitQueue',
            'keydown .nexus-prompt':                  'onKeydown',
        };

        _lastPrompt = null;

        data() {
            return { nexusEnabled: this.getConfig().get('nexusEnabled') !== false };
        }

        onAsk() {
            const prompt = this.$el.find('.nexus-prompt').val().trim();
            if (!prompt) return;
            this._lastPrompt = prompt;
            this.$el.find('[data-action="nexusAsk"]').prop('disabled', true);
            this.$el.find('.nexus-loading').show();
            this.$el.find('.nexus-error').hide();
            this.$el.find('.nexus-result').hide();
            this.$el.find('.nexus-queue-fallback').hide();

            Espo.Ajax.postRequest('nexus/chat', {
                message:    prompt,
                entityType: this.model.entityType,
                entityId:   this.model.id,
            })
            .then(result => this._showResult(result))
            .catch(xhr => {
                const isTimeout = !xhr.status || xhr.status === 502 || xhr.status === 504;
                const msg = isTimeout
                    ? 'NEXUS is thinking… request timed out.'
                    : ((xhr.responseJSON && xhr.responseJSON.error) || 'NEXUS request failed.');
                this.$el.find('.nexus-error').text(msg).show();
                if (isTimeout) this.$el.find('.nexus-queue-fallback').show();
            })
            .finally(() => {
                this.$el.find('[data-action="nexusAsk"]').prop('disabled', false);
                this.$el.find('.nexus-loading').hide();
            });
        }

        onSubmitQueue(e) {
            e.preventDefault();
            if (!this._lastPrompt) return;
            this.$el.find('.nexus-queue-fallback').hide();
            this.$el.find('.nexus-error').hide();

            Espo.Ajax.postRequest('nexus/submit', {
                prompt:  this._lastPrompt,
                urgency: 'urgent',
                label:   'EspoCRM ' + this.model.entityType + ' ' + this.model.id,
                context: { entityType: this.model.entityType, entityId: this.model.id },
            })
            .then(result => {
                this.$el.find('.nexus-result-meta').text('Job ' + result.job_id + ' queued — checking every 5s…');
                this.$el.find('.nexus-result-text').text('');
                this.$el.find('.nexus-result').show();
                this._pollJob(result.job_id, 0);
            })
            .catch(xhr => {
                const msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Failed to queue job.';
                this.$el.find('.nexus-error').text(msg).show();
            });
        }

        _pollJob(jobId, n) {
            if (n > 60) {
                this.$el.find('.nexus-result-meta').text('Job ' + jobId + ' — timed out waiting for result.');
                return;
            }
            setTimeout(() => {
                Espo.Ajax.getRequest('nexus/result/' + encodeURIComponent(jobId))
                .then(r => {
                    if (r.status === 'completed') {
                        this._showResult(r);
                    } else if (r.status === 'failed') {
                        this.$el.find('.nexus-result-meta').text(
                            'Job ' + jobId + ' failed' + (r.error_message ? ': ' + r.error_message : '.')
                        );
                        this.$el.find('.nexus-result-text').text('');
                    } else {
                        const pos = r.position_in_queue ? ' (queue pos ' + r.position_in_queue + ')' : '';
                        this.$el.find('.nexus-result-meta').text(
                            'Job ' + jobId + ' — ' + (r.status || 'running') + pos + ' …'
                        );
                        this._pollJob(jobId, n + 1);
                    }
                })
                .catch(() => this._pollJob(jobId, n + 1));
            }, 5000);
        }

        onKeydown(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') this.onAsk();
        }

        _showResult(r) {
            const text = r.reply || r.result_text || r.response || r.content;
            const model = r.model_used || r.model || '';
            const tier  = r.tier_used || '';
            const meta  = [model, tier].filter(Boolean).join(' · ');

            this.$el.find('.nexus-result-meta').text(meta);

            if (text) {
                this.$el.find('.nexus-result-text').text(text);
            } else {
                // Completed but empty — model returned no tokens
                this.$el.find('.nexus-result-text')
                    .html('<em style="color:#aaa;">NEXUS returned an empty response. ' +
                          'The model may need a moment — try again or rephrase your question.</em>');
            }
            this.$el.find('.nexus-result').show();
        }
    };
});
