(function($) {
    'use strict';

    $(document).ready(function() {
        const method = 'bayarcash';
        const $tokenField = $('textarea[name="edd_settings[bayarcash_token]"]');
        const $portalKeySelect = $('select[name="edd_settings[bayarcash_portal_key]"]');

        if ($tokenField.length === 0) {
            return;
        }

        const verifyButtonHtml = `
            <div id="${method}-verify-wrapper" style="margin-top: 10px;">
                <button type="button" id="${method}-verify-button" class="button-secondary">Verify Token</button>
                <span id="${method}-verify-status" style="margin-left: 10px;"></span>
            </div>
        `;
        $tokenField.after(verifyButtonHtml);

        const app = createVueApp(method, $tokenField, $portalKeySelect);
        app.mount(`#${method}-verify-wrapper`);

        function createVueApp(method, $tokenField, $portalKeySelect) {
            return Vue.createApp({
                data() {
                    return {
                        method,
                        statusText: '',
                        status: null,
                        portalInfo: null,
                        savedSettings: {},
                        merchantName: ''
                    };
                },
                computed: {
                    isTokenValid() {
                        return this.status === 1;
                    },
                    filteredPortals() {
                        return this.portalInfo ? this.portalInfo.portals : [];
                    }
                },
                methods: {
                    async loadSavedSettings() {
                        let bayarcashEddAdminData;
                        if (typeof bayarcashEddAdminData === 'undefined') {
                            return;
                        }
                        try {
                            const response = await $.ajax({
                                url: bayarcashEddAdminData.ajaxurl,
                                method: 'POST',
                                data: {
                                    action: 'get_bayarcash_edd_settings',
                                    nonce: bayarcashEddAdminData.nonce
                                }
                            });
                            this.savedSettings = JSON.parse(response);
                        } catch (error) {
                            console.error('Error loading saved settings:', error);
                        }
                        this.populateFields();
                    },
                    populateFields() {
                        const { bayarcash_token, bayarcash_portal_key } = this.savedSettings;
                        if (bayarcash_token) {
                            $tokenField.val(bayarcash_token);
                        }
                        if (bayarcash_portal_key) {
                            $portalKeySelect.val(bayarcash_portal_key);
                        }
                    },
                    async verifyToken(event) {
                        if (event) event.preventDefault();

                        const token = $.trim($tokenField.val()) || '';
                        if (token === '') {
                            this.setStatus(`Please insert Token.`, 0);
                            this.clearPortalInfo();
                            this.clearFields();
                            return false;
                        }

                        const apiUrl = 'https://console.bayar.cash/api/v2/portals';
                        this.setStatus(`Validating PAT token..`, null);

                        try {
                            const response = await this.makeApiCall(apiUrl, token);
                            this.handleApiResponse(response);
                        } catch (error) {
                            this.handleInvalidToken();
                        }
                    },
                    async makeApiCall(apiUrl, token) {
                        return await axios.get(apiUrl, {
                            headers: {
                                'Accept': 'application/json',
                                'Authorization': `Bearer ${token}`
                            }
                        });
                    },
                    handleApiResponse(response) {
                        if (response.status === 200) {
                            this.setStatus(`PAT Token is valid`, 1);
                            const portalsList = response.data.data;
                            if (portalsList.length > 0) {
                                this.updatePortalInfo(response.data);
                                this.merchantName = response.data.meta.merchant.name;
                                this.displayMerchantName();
                            } else {
                                this.clearPortalInfo();
                                this.merchantName = '';
                                this.removeMerchantNameDisplay();
                            }
                            this.populatePortalKeyDropdown(portalsList);
                        } else {
                            this.handleInvalidToken();
                        }
                    },
                    setStatus(text, status) {
                        this.statusText = text + (status === 1 ? ' <span class="dashicons dashicons-yes-alt"></span>' :
                            status === 0 ? ' <span class="dashicons dashicons-dismiss"></span>' : '');
                        this.status = status;
                        $(`#${this.method}-verify-status`).html(this.statusText).removeClass('valid invalid').addClass(status === 1 ? 'valid' : status === 0 ? 'invalid' : '');
                    },
                    updatePortalInfo(data) {
                        this.portalInfo = {
                            merchantName: data.meta.merchant.name,
                            portals: data.data
                        };
                    },
                    clearPortalInfo() {
                        this.portalInfo = null;
                        $('#portal-info').remove();
                    },
                    populatePortalKeyDropdown(portalsList) {
                        const currentValue = $portalKeySelect.val() || this.savedSettings.bayarcash_portal_key;
                        $portalKeySelect.empty();

                        if (portalsList.length === 0) {
                            $portalKeySelect.append($('<option>', { value: '', text: 'Select a portal' }));
                        } else {
                            portalsList.forEach(portal => {
                                $portalKeySelect.append($('<option>', {
                                    value: portal.portal_key,
                                    text: `${portal.portal_name} (${portal.portal_key})`
                                }));
                            });
                        }

                        if (currentValue && $portalKeySelect.find(`option[value="${currentValue}"]`).length > 0) {
                            $portalKeySelect.val(currentValue);
                        }
                    },
                    handleInvalidToken() {
                        this.setStatus(`Invalid PAT Token`, 0);
                        this.clearPortalInfo();
                        this.clearFields();

                        $portalKeySelect.empty().append($('<option>', {
                            value: '',
                            text: 'Please Insert Valid Token'
                        }));
                    },
                    clearFields() {
                        $portalKeySelect.val('');
                    },
                    displayMerchantName() {
                        const merchantNameElementId = `${this.method}-merchant-name`;
                        $(`#${merchantNameElementId}`).remove();
                        if (this.merchantName) {
                            const merchantNameElement = $('<div>', {
                                id: merchantNameElementId,
                                class: 'description',
                                html: `<strong>Merchant Name:</strong> ${this.merchantName}`,
                                css: {
                                    'margin-top': '10px',
                                    'margin-bottom': '10px'
                                }
                            });
                            $(`#${this.method}-verify-status`).after(merchantNameElement);
                        }
                    },
                    removeMerchantNameDisplay() {
                        $(`#${this.method}-merchant-name`).remove();
                    }
                },
                mounted() {
                    this.loadSavedSettings().then(() => {
                        if ($tokenField.val().trim() !== '') {
                            this.verifyToken();
                        }
                    });

                    $(`#${this.method}-verify-button`).on('click', () => {
                        this.verifyToken();
                    });

                    $tokenField.on('input', _.debounce(() => {
                        this.verifyToken();
                    }, 500));
                }
            });
        }
    });
})(jQuery);