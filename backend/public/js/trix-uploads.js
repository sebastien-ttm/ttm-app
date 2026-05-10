/**
 * Wires the Trix editor (used by EasyAdmin's TextEditorField) to our
 * /admin/upload/inline endpoint so dragged/pasted images are uploaded
 * server-side and embedded as <img src="..."> in the content.
 */
(function () {
    'use strict';

    const ENDPOINT = '/admin/upload/inline';

    function uploadAttachment(attachment) {
        const file = attachment.file;
        if (!file) return; // already uploaded (e.g. on edit reload)

        const form = new FormData();
        form.append('file', file);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', ENDPOINT, true);

        xhr.upload.onprogress = function (event) {
            if (event.lengthComputable) {
                attachment.setUploadProgress((event.loaded / event.total) * 100);
            }
        };

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.url) {
                        attachment.setAttributes({ url: data.url, href: data.url });
                        return;
                    }
                } catch (e) {
                    // fall through to failure
                }
            }
            // Failure: remove attachment and show alert
            try {
                const data = JSON.parse(xhr.responseText);
                window.alert('Échec de l\'upload : ' + (data.error || xhr.statusText));
            } catch (e) {
                window.alert('Échec de l\'upload (HTTP ' + xhr.status + ')');
            }
            attachment.remove();
        };

        xhr.onerror = function () {
            window.alert('Erreur réseau pendant l\'upload de l\'image.');
            attachment.remove();
        };

        xhr.send(form);
    }

    document.addEventListener('trix-attachment-add', function (event) {
        if (event.attachment && event.attachment.file) {
            uploadAttachment(event.attachment);
        }
    });
})();
