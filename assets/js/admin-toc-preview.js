(function ($) {
    function getContentGutenberg() {
        if (window.wp && wp.data && wp.data.select) {
            var editor = wp.data.select('core/editor');
            if (editor && editor.getEditedPostContent) {
                return editor.getEditedPostContent() || '';
            }
        }
        return '';
    }

    function getContentClassic() {
        var $textarea = $('#content');
        if ($textarea.length) {
            return $textarea.val() || '';
        }
        return '';
    }

    function generateTOCHTMLFromContent(content) {
        if (!content) {
            return '<em>No content yet. Start adding H2 or H3 headings in your post.</em>';
        }

        // Create DOM to parse
        var parser = new DOMParser();
        var doc = parser.parseFromString(content, 'text/html');
        var headings = doc.querySelectorAll('h2, h3');

        if (!headings.length) {
            return '<em>No H2/H3 headings found in the content.</em>';
        }

        var html = '<div class="wptoc-container-preview">';
        html += '<div class="wptoc-title">Table of Contents (Preview)</div>';
        html += '<ul class="wptoc-list">';

        headings.forEach(function (h) {
            var text = h.textContent.trim();
            if (!text) return;
            var tag = h.tagName.toLowerCase();

            var indentClass = tag === 'h3' ? 'wptoc-item--child' : 'wptoc-item--parent';
            html += '<li class="wptoc-item ' + indentClass + '">' +
                '<span>' + text + '</span>' +
                '</li>';
        });

        html += '</ul></div>';

        html += '<style>
            #wptoc-preview .wptoc-container-preview{font-size:12px;}
            #wptoc-preview .wptoc-title{font-weight:600;margin-bottom:4px;}
            #wptoc-preview .wptoc-list{list-style:none;margin:0;padding-left:0;}
            #wptoc-preview .wptoc-item{margin:2px 0;}
            #wptoc-preview .wptoc-item--child{margin-left:14px;font-size:11px;}
        </style>';

        return html;
    }

    function updatePreview() {
        var $preview = $('#wptoc-preview');
        if (!$preview.length) return;

        var content = getContentGutenberg();
        if (!content) {
            content = getContentClassic();
        }

        var html = generateTOCHTMLFromContent(content);
        $preview.html(html);
    }

    $(function () {
        // initial
        updatePreview();

        // For Gutenberg: subscribe to editor changes
        if (window.wp && wp.data && wp.data.subscribe) {
            var lastContent = null;
            wp.data.subscribe(function () {
                var content = getContentGutenberg();
                if (content !== lastContent) {
                    lastContent = content;
                    updatePreview();
                }
            });
        }

        // For Classic: simple keyup listener
        $('#content').on('keyup change', function () {
            updatePreview();
        });
    });
})(jQuery);
