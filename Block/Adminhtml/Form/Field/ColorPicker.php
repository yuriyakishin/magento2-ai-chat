<?php
declare(strict_types=1);

namespace Yu\AiChat\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a native HTML5 color input next to the text field and keeps the
 * two in sync. The text field stays authoritative: the picker only offers
 * #rrggbb, while the field still accepts #rgb typed by hand (expanded for
 * the picker on the fly) and is validated server-side by HexColor.
 */
class ColorPicker extends Field
{
    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $textId = $element->getHtmlId();
        $pickerId = $textId . '_picker';
        // Admin text inputs stretch to the full control column, which pushes
        // the picker onto the next line; a fixed width keeps them side by side.
        $element->setData('style', 'width:140px;vertical-align:middle;');
        $html = parent::_getElementHtml($element);
        $html .= '<input type="color" id="' . $pickerId . '"'
            . ' style="margin-left:8px;height:33px;width:44px;vertical-align:middle;cursor:pointer"/>';
        $html .= '<script>require(["domReady!"], function () {
            var t = document.getElementById("' . $textId . '");
            var p = document.getElementById("' . $pickerId . '");
            if (!t || !p) { return; }
            var sync = function () {
                var v = t.value.trim();
                if (/^#[0-9a-fA-F]{3}$/.test(v)) {
                    v = "#" + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
                }
                if (/^#[0-9a-fA-F]{6}$/.test(v)) { p.value = v.toLowerCase(); }
            };
            p.addEventListener("input", function () {
                t.value = p.value;
                t.dispatchEvent(new Event("change"));
            });
            t.addEventListener("input", sync);
            sync();
        });</script>';
        return $html;
    }
}
