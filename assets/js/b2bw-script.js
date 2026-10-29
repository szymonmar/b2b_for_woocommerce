document.addEventListener('DOMContentLoaded', function() {

    const info = document.querySelector('span.b2b-price-info');                 // 'Individual price' label
    const omnibusWrapper = document.querySelector('div.omnibus-price');         // Lowest price information wrapper (integration with Lowest Price History - Omnibus plugin)
    const b2bWrapper = document.querySelector('div.b2b-price-info-wrapper');    // Outer wrapper of the 'Individual price' label

    /**
     * get the ids of variations that have a individual price set
     */
    try {
        variationIds = info?.dataset.variationIds
            ? JSON.parse(info.dataset.variationIds)
            : [];
    } catch (e) {
        variationIds = [];
    }

    /**
     * Updates visibility of a individual price label on the frontend
     * @param {int} id - id of the selected varation
     * @returns {void}
     */
    function updateB2BPriceInfo(id) {
        if(!variationIds || variationIds.length === 0) {
            return;
        } 
        if (variationIds.includes(Number(id))) {
            info.style.removeProperty('display');
            if(omnibusWrapper) {
                omnibusWrapper.classList.add("hide-wrapper");
                b2bWrapper.classList.remove("hide-wrapper");
            }
        } else {
            info.style.display = 'none';
            if(omnibusWrapper) {
                omnibusWrapper.classList.remove("hide-wrapper");
                b2bWrapper.classList.add("hide-wrapper");
            }
        }
    }

    // Waits for default variation initialization
    const startTime = Date.now();
    const timeout = 5000;

    const interval = setInterval(function () {
        const id = input.value;

        if (id && id !== '0') {
            updateB2BPriceInfo(id);
            clearInterval(interval);
        } else if (Date.now() - startTime >= timeout) {
            clearInterval(interval);
            console.warn("B2B for WooCommerce: variation not found.");
        }
    }, 100);

    // Listens for variation changes
    jQuery(function ($) {
        $('input[name="variation_id"]').on('change', function () {
            updateB2BPriceInfo(this.value);
        });
    });

});