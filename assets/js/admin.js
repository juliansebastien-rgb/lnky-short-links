(function () {
    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }

        callback();
    }

    onReady(function () {
        var modeInputs = document.querySelectorAll('input[name="target_mode"]');
        var slugInput = document.getElementById('lnky_slug');
        var selectedDomainInput = document.getElementById('lnky_selected_domain');
        var subdomainInput = document.getElementById('lnky_workspace_subdomain');
        var basePathInput = document.getElementById('lnky_local_base_path');
        var livePublicPreview = document.getElementById('lnky_live_public_preview');
        var liveLocalPreview = document.getElementById('lnky_live_local_preview');
        var settingsPublicPreview = document.getElementById('lnky_settings_public_preview');
        var settingsLocalPreview = document.getElementById('lnky_settings_local_preview');
        var searchInput = document.getElementById('lnky_internal_search');
        var typeSelect = document.getElementById('lnky_internal_type');
        var resultsBox = document.getElementById('lnky_search_results');
        var postIdInput = document.getElementById('lnky_destination_post_id');
        var labelInput = document.getElementById('lnky_destination_label');
        var urlInput = document.getElementById('lnky_destination_url_snapshot');
        var selectedItem = document.getElementById('lnky_selected_item');
        var selectedLabel = selectedItem ? selectedItem.querySelector('[data-lnky-selected-label]') : null;
        var selectedUrl = selectedItem ? selectedItem.querySelector('[data-lnky-selected-url]') : null;
        var searchAbort = null;
        var searchTimer = null;

        function toggleMode() {
            var mode = document.querySelector('input[name="target_mode"]:checked');
            var modeValue = mode ? mode.value : 'external';

            document.querySelectorAll('[data-target-mode]').forEach(function (section) {
                section.hidden = section.getAttribute('data-target-mode') !== modeValue;
            });
        }

        function sanitizePart(value, fallback) {
            var normalized = (value || '')
                .toLowerCase()
                .replace(/[^a-z0-9-]/g, '')
                .replace(/^-+|-+$/g, '');

            return normalized || fallback;
        }

        function trimSlashes(value) {
            return (value || '').replace(/^\/+|\/+$/g, '');
        }

        function trimTrailingSlashes(value) {
            return (value || '').replace(/\/+$/g, '');
        }

        function updateLinkPreviews() {
            if (!livePublicPreview && !liveLocalPreview) {
                return;
            }

            var slug = sanitizePart(slugInput ? slugInput.value : '', 'slug-auto');
            var domain = livePublicPreview ? (livePublicPreview.getAttribute('data-lnky-preview-domain') || 'lnky.fr') : 'lnky.fr';
            var subdomain = livePublicPreview ? sanitizePart(livePublicPreview.getAttribute('data-lnky-preview-subdomain') || '', '') : '';
            var host = subdomain ? subdomain + '.' + domain : domain;

            if (livePublicPreview) {
                livePublicPreview.textContent = host + '/' + slug;
            }

            if (liveLocalPreview) {
                var basePath = trimSlashes(liveLocalPreview.getAttribute('data-lnky-preview-base-path') || 'lnky');
                var home = trimTrailingSlashes(liveLocalPreview.getAttribute('data-lnky-preview-home') || '');
                liveLocalPreview.textContent = home + '/' + basePath + '/' + slug + '/';
            }
        }

        function updateSettingsPreviews() {
            if (!settingsPublicPreview && !settingsLocalPreview) {
                return;
            }

            var domain = sanitizePart(selectedDomainInput ? selectedDomainInput.value : '', 'lnkyfr').replace(/-/g, '');

            if (selectedDomainInput && selectedDomainInput.value) {
                domain = selectedDomainInput.value.trim().toLowerCase();
            }

            var subdomain = sanitizePart(subdomainInput ? subdomainInput.value : '', '');
            var basePath = sanitizePart(basePathInput ? basePathInput.value : '', 'lnky');
            var host = subdomain ? subdomain + '.' + domain : domain;

            if (settingsPublicPreview) {
                settingsPublicPreview.textContent = host + '/offre';
            }

            if (settingsLocalPreview) {
                var home = trimTrailingSlashes(settingsLocalPreview.getAttribute('data-lnky-settings-home') || '');
                settingsLocalPreview.textContent = home + '/' + basePath + '/offre/';
            }

            if (livePublicPreview) {
                livePublicPreview.setAttribute('data-lnky-preview-domain', domain);
                livePublicPreview.setAttribute('data-lnky-preview-subdomain', subdomain);
            }

            if (liveLocalPreview) {
                liveLocalPreview.setAttribute('data-lnky-preview-base-path', basePath);
            }

            updateLinkPreviews();
        }

        function renderSelected(label, url) {
            if (!selectedItem || !selectedLabel || !selectedUrl) {
                return;
            }

            selectedLabel.textContent = label || '';
            selectedUrl.textContent = url || '';
            selectedItem.hidden = !(label || url);
        }

        function clearResults(message) {
            if (!resultsBox) {
                return;
            }

            resultsBox.innerHTML = message ? '<div class="lnky-search-results__empty">' + message + '</div>' : '';
        }

        function performSearch() {
            if (!searchInput || !typeSelect || !resultsBox || !window.LnkyAdmin) {
                return;
            }

            var term = searchInput.value.trim();

            if (term.length < LnkyAdmin.searchMinChars) {
                clearResults(LnkyAdmin.messages.needsMore);
                return;
            }

            clearResults(LnkyAdmin.messages.searching);

            if (searchAbort) {
                searchAbort.abort();
            }

            searchAbort = new AbortController();

            var params = new URLSearchParams({
                action: 'lnky_search_content',
                nonce: LnkyAdmin.nonce,
                search: term,
                post_type: typeSelect.value
            });

            fetch(LnkyAdmin.ajaxUrl + '?' + params.toString(), {
                credentials: 'same-origin',
                signal: searchAbort.signal
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload || !payload.success || !payload.data) {
                        clearResults(LnkyAdmin.messages.empty);
                        return;
                    }

                    var items = payload.data.items || [];

                    if (!items.length) {
                        clearResults(LnkyAdmin.messages.empty);
                        return;
                    }

                    resultsBox.innerHTML = '';

                    items.forEach(function (item) {
                        var row = document.createElement('button');
                        row.type = 'button';
                        row.className = 'lnky-search-results__item';
                        row.innerHTML = '<strong>' + item.title + '</strong><span>' + item.url + '</span>';
                        row.addEventListener('click', function () {
                            postIdInput.value = item.id;
                            labelInput.value = item.title;
                            urlInput.value = item.url;
                            searchInput.value = item.title;
                            renderSelected(item.title, item.url);
                            clearResults('');
                        });
                        resultsBox.appendChild(row);
                    });
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }

                    clearResults(LnkyAdmin.messages.empty);
                });
        }

        modeInputs.forEach(function (input) {
            input.addEventListener('change', toggleMode);
        });

        toggleMode();
        renderSelected(labelInput ? labelInput.value : '', urlInput ? urlInput.value : '');
        updateSettingsPreviews();
        updateLinkPreviews();

        if (slugInput) {
            slugInput.addEventListener('input', updateLinkPreviews);
        }

        if (selectedDomainInput) {
            selectedDomainInput.addEventListener('change', updateSettingsPreviews);
        }

        if (subdomainInput) {
            subdomainInput.addEventListener('input', updateSettingsPreviews);
        }

        if (basePathInput) {
            basePathInput.addEventListener('input', updateSettingsPreviews);
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                if (searchTimer) {
                    window.clearTimeout(searchTimer);
                }

                searchTimer = window.setTimeout(performSearch, 250);
            });
        }

        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                if (searchInput && searchInput.value.trim().length >= (window.LnkyAdmin ? LnkyAdmin.searchMinChars : 2)) {
                    performSearch();
                }
            });
        }
    });
})();
