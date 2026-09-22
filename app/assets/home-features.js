(function () {
  'use strict';

  /*
   * ============================================================
   * هزینه ۹ ماهه سرویس حمل و نقل دانش‌آموزی
   * ============================================================
   *
   * مبلغ ماهیانه حفظ می‌شود.
   *
   * ترتیب نهایی:
   *
   * هزینه ماهیانه سرویس: ۱۵٬۰۸۲٬۰۱۹ ریال
   * هزینه ۹ ماهه سرویس حمل و نقل دانش‌آموزی
   * ۱۳۵٬۷۳۸٬۱۷۱ ریال
   *
   * هر دو مبلغ بزرگ‌تر و برجسته‌تر از متن عادی هستند.
   *
   * نتیجه داخل همان کادر محاسبه قرار می‌گیرد.
   * ============================================================
   */

  var MONTHS = 9;

  var RESULT_CLASS =
    'school-transport-9-month-result';

  var MONTHLY_AMOUNT_CLASS =
    'school-transport-monthly-amount';

  var STYLE_ID =
    'school-transport-9-month-style';

  var monthlyRegex =
    /هزینه\s*(?:ماهیانه|ماهانه)\s*سرویس\s*[:：]?\s*([۰-۹0-9٬,\s]+)(?:\s*ریال)?/i;


  // ==========================================================
  // تبدیل اعداد فارسی و عربی به انگلیسی
  // ==========================================================

  function normalizeDigits(value) {

    if (value == null) {
      return '';
    }

    return String(value)

      .replace(
        /[۰-۹]/g,
        function (digit) {
          return String(
            '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)
          );
        }
      )

      .replace(
        /[٠-٩]/g,
        function (digit) {
          return String(
            '٠١٢٣٤٥٦٧٨٩'.indexOf(digit)
          );
        }
      );
  }


  // ==========================================================
  // تبدیل متن مبلغ به عدد
  // ==========================================================

  function parseAmount(value) {

    var normalized =
      normalizeDigits(value);

    normalized =
      normalized
        .replace(/[٬,،\s]/g, '')
        .replace(/[^\d]/g, '');

    if (!normalized) {
      return 0;
    }

    var amount =
      Number(normalized);

    if (
      !Number.isFinite(amount) ||
      amount <= 0
    ) {
      return 0;
    }

    return amount;
  }


  // ==========================================================
  // فرمت مبلغ به فارسی
  // ==========================================================

  function formatNumber(value) {

    return new Intl.NumberFormat(
      'fa-IR'
    ).format(value);
  }


  // ==========================================================
  // حذف عبارت تومان
  // ==========================================================

  function removeTomanText(root) {

    if (!root) {
      return;
    }

    var walker =
      document.createTreeWalker(
        root,
        NodeFilter.SHOW_TEXT,
        null
      );

    var nodes = [];

    var node;

    while (
      (node = walker.nextNode())
    ) {
      nodes.push(node);
    }


    nodes.forEach(
      function (textNode) {

        var text =
          textNode.nodeValue || '';

        if (
          text.indexOf('تومان') === -1
        ) {
          return;
        }


        var cleaned =
          text.replace(
            /(?:معادل\s*)?[۰-۹0-9٬,\s]+(?:\s*تومان)/g,
            ''
          );


        cleaned =
          cleaned.replace(
            /معادل\s*$/g,
            ''
          );


        if (
          cleaned !== text
        ) {
          textNode.nodeValue =
            cleaned;
        }
      }
    );
  }


  // ==========================================================
  // پیدا کردن مبلغ ماهیانه
  // ==========================================================

  function extractMonthlyAmount(text) {

    if (!text) {
      return 0;
    }

    var match =
      String(text).match(
        monthlyRegex
      );

    if (
      !match ||
      !match[1]
    ) {
      return 0;
    }

    return parseAmount(
      match[1]
    );
  }


  // ==========================================================
  // پیدا کردن کادر محاسبه
  // ==========================================================

  function findCalculationContainer() {

    var all =
      document.querySelectorAll(
        'body *'
      );

    var best = null;

    var bestLength =
      Infinity;


    for (
      var i = 0;
      i < all.length;
      i++
    ) {

      var element =
        all[i];


      if (
        element.classList &&
        element.classList.contains(
          RESULT_CLASS
        )
      ) {
        continue;
      }


      var text =
        element.innerText ||
        element.textContent ||
        '';


      if (
        !/هزینه\s*(?:ماهیانه|ماهانه)\s*سرویس/i.test(
          text
        )
      ) {
        continue;
      }


      var length =
        text.trim().length;


      if (
        length > 0 &&
        length < bestLength
      ) {

        best =
          element;

        bestLength =
          length;
      }
    }


    return best;
  }


  // ==========================================================
  // حذف نتیجه‌های قبلی
  // ==========================================================

  function removeOldResults(root) {

    if (!root) {
      return;
    }

    var results =
      root.querySelectorAll(
        '.' + RESULT_CLASS
      );


    results.forEach(
      function (element) {
        element.remove();
      }
    );
  }


  // ==========================================================
  // ساخت مبلغ ۹ ماهه
  // ==========================================================

  function createNineMonthResult(
    monthlyAmount
  ) {

    var nineMonthAmount =
      monthlyAmount * MONTHS;


    var result =
      document.createElement(
        'div'
      );


    result.className =
      RESULT_CLASS;


    result.setAttribute(
      'dir',
      'rtl'
    );


    result.innerHTML =

      '<div class="school-transport-9-month-label">' +
        'هزینه ۹ ماهه سرویس حمل و نقل دانش‌آموزی' +
      '</div>' +

      '<div class="school-transport-9-month-value">' +
        formatNumber(
          nineMonthAmount
        ) +
        ' ریال' +
      '</div>';


    return result;
  }


  // ==========================================================
  // درشت کردن مبلغ ماهیانه موجود
  // ==========================================================

  function styleMonthlyAmount(
    monthlyElement
  ) {

    if (!monthlyElement) {
      return;
    }


    /*
     * اگر خود عنصر فقط خط هزینه ماهیانه است،
     * کلاس را مستقیماً روی آن قرار می‌دهیم.
     */

    var text =
      monthlyElement.innerText ||
      monthlyElement.textContent ||
      '';


    if (
      /هزینه\s*(?:ماهیانه|ماهانه)\s*سرویس/i.test(
        text
      )
    ) {

      monthlyElement.classList.add(
        MONTHLY_AMOUNT_CLASS
      );
    }
  }


  // ==========================================================
  // پیدا کردن دقیق‌ترین عنصر هزینه ماهیانه
  // ==========================================================

  function findMonthlyElement(container) {

    if (!container) {
      return null;
    }


    var all =
      container.querySelectorAll(
        '*'
      );

    var best = null;

    var bestLength =
      Infinity;


    for (
      var i = 0;
      i < all.length;
      i++
    ) {

      var element =
        all[i];


      if (
        element.classList &&
        element.classList.contains(
          RESULT_CLASS
        )
      ) {
        continue;
      }


      var text =
        element.innerText ||
        element.textContent ||
        '';


      if (
        !/هزینه\s*(?:ماهیانه|ماهانه)\s*سرویس/i.test(
          text
        )
      ) {
        continue;
      }


      var length =
        text.trim().length;


      if (
        length > 0 &&
        length < bestLength
      ) {

        best =
          element;

        bestLength =
          length;
      }
    }


    return best;
  }


  // ==========================================================
  // درج نتیجه بعد از خط ماهیانه
  // ==========================================================

  function insertResultAfterMonthlyLine(
    monthlyElement,
    monthlyAmount
  ) {

    if (
      !monthlyElement ||
      !monthlyAmount
    ) {
      return;
    }


    /*
     * اگر نتیجه قبلاً بعد از همین عنصر قرار گرفته،
     * دوباره ایجاد نکن.
     */

    var next =
      monthlyElement.nextElementSibling;


    if (
      next &&
      next.classList &&
      next.classList.contains(
        RESULT_CLASS
      )
    ) {

      return;
    }


    var result =
      createNineMonthResult(
        monthlyAmount
      );


    if (!result) {
      return;
    }


    /*
     * نتیجه دقیقاً بعد از خط ماهیانه قرار می‌گیرد.
     */

    monthlyElement.parentNode.insertBefore(
      result,
      monthlyElement.nextSibling
    );
  }


  // ==========================================================
  // حالت TextNode
  // ==========================================================

  function insertIntoTextNode(
    container,
    monthlyAmount
  ) {

    if (!container) {
      return false;
    }


    var walker =
      document.createTreeWalker(
        container,
        NodeFilter.SHOW_TEXT,
        null
      );


    var nodes = [];

    var node;

    while (
      (node = walker.nextNode())
    ) {

      nodes.push(node);
    }


    for (
      var i = 0;
      i < nodes.length;
      i++
    ) {

      var textNode =
        nodes[i];


      var text =
        textNode.nodeValue || '';


      var match =
        text.match(
          monthlyRegex
        );


      if (!match) {
        continue;
      }


      var parent =
        textNode.parentNode;


      if (!parent) {
        continue;
      }


      /*
       * اگر این TextNode داخل نتیجه قبلی است،
       * از آن عبور کن.
       */

      if (
        parent.closest &&
        parent.closest(
          '.' + RESULT_CLASS
        )
      ) {
        continue;
      }


      var beforeText =
        text.substring(
          0,
          match.index
        );


      var matchedText =
        match[0];


      var afterText =
        text.substring(
          match.index +
          matchedText.length
        );


      /*
       * خط ماهیانه را به یک عنصر مستقل تبدیل می‌کنیم
       * تا مبلغ آن بتواند درشت شود.
       */

      var monthlyLine =
        document.createElement(
          'span'
        );


      monthlyLine.className =
        MONTHLY_AMOUNT_CLASS;


      monthlyLine.setAttribute(
        'dir',
        'rtl'
      );


      monthlyLine.textContent =
        matchedText;


      var result =
        createNineMonthResult(
          monthlyAmount
        );


      if (beforeText) {

        parent.insertBefore(
          document.createTextNode(
            beforeText
          ),
          textNode
        );
      }


      parent.insertBefore(
        monthlyLine,
        textNode
      );


      parent.insertBefore(
        result,
        textNode
      );


      if (afterText) {

        parent.insertBefore(
          document.createTextNode(
            afterText
          ),
          textNode
        );
      }


      parent.removeChild(
        textNode
      );


      return true;
    }


    return false;
  }


  // ==========================================================
  // پردازش اصلی
  // ==========================================================

  function processCalculation() {

    var container =
      findCalculationContainer();


    if (!container) {
      return;
    }


    /*
     * حذف تومان قبل از پردازش.
     */

    removeTomanText(
      container
    );


    /*
     * متن کامل کادر.
     */

    var fullText =
      container.innerText ||
      container.textContent ||
      '';


    var monthlyAmount =
      extractMonthlyAmount(
        fullText
      );


    if (!monthlyAmount) {
      return;
    }


    /*
     * نتیجه‌های قبلی را پاک می‌کنیم.
     */

    removeOldResults(
      container
    );


    /*
     * پیدا کردن خط هزینه ماهیانه.
     */

    var monthlyElement =
      findMonthlyElement(
        container
      );


    /*
     * اگر خط ماهیانه یک عنصر مستقل است،
     * نتیجه را دقیقاً زیر همان قرار بده.
     */

    if (
      monthlyElement &&
      monthlyElement !== container
    ) {

      styleMonthlyAmount(
        monthlyElement
      );


      insertResultAfterMonthlyLine(
        monthlyElement,
        monthlyAmount
      );


      return;
    }


    /*
     * اگر کل متن داخل یک عنصر واحد است،
     * TextNode را به صورت دقیق تقسیم می‌کنیم.
     */

    insertIntoTextNode(
      container,
      monthlyAmount
    );
  }


  // ==========================================================
  // CSS
  // ==========================================================

  function installStyles() {

    var oldStyle =
      document.getElementById(
        STYLE_ID
      );


    if (oldStyle) {
      oldStyle.remove();
    }


    var style =
      document.createElement(
        'style'
      );


    style.id =
      STYLE_ID;


    style.textContent = `

      /*
       * ========================================================
       * مبلغ ماهیانه
       * ========================================================
       *
       * متن عنوان و مبلغ با هم حفظ می‌شوند،
       * اما کل خط کمی درشت‌تر از متن معمولی است.
       */

      .${MONTHLY_AMOUNT_CLASS} {

        display: block;

        width: 100%;

        margin: 2px 0 0 0;

        padding: 0;

        border: 0;

        background: transparent;

        box-shadow: none;

        direction: rtl;

        text-align: right;

        font-family:
          "Vazirmatn",
          "Vazir",
          Tahoma,
          sans-serif;

        font-size: 16px;

        line-height: 2;

        font-weight: 800;

        box-sizing: border-box;
      }


      /*
       * ========================================================
       * بخش ۹ ماهه
       * ========================================================
       */

      .${RESULT_CLASS} {

        display: block;

        width: 100%;

        margin: 4px 0 0 0;

        padding: 0;

        border: 0;

        border-radius: 0;

        background: transparent;

        box-shadow: none;

        direction: rtl;

        text-align: right;

        font-family:
          "Vazirmatn",
          "Vazir",
          Tahoma,
          sans-serif;

        box-sizing: border-box;
      }


      /*
       * عنوان ۹ ماهه
       */

      .school-transport-9-month-label {

        display: block;

        margin: 0;

        padding: 0;

        font-size: 14px;

        line-height: 1.9;

        font-weight: 700;

        color: inherit;
      }


      /*
       * مبلغ ۹ ماهه
       *
       * عمداً از متن معمولی بزرگ‌تر است.
       */

      .school-transport-9-month-value {

        display: block;

        margin: 0;

        padding: 0;

        font-size: 16px;

        line-height: 2;

        font-weight: 800;

        color: inherit;
      }

    `;


    document.head.appendChild(
      style
    );
  }


  // ==========================================================
  // اجرای اولیه
  // ==========================================================

  function run() {

    installStyles();

    processCalculation();
  }


  // ==========================================================
  // MutationObserver
  // ==========================================================

  function observe() {

    if (!document.body) {
      return;
    }


    var timer = null;


    var observer =
      new MutationObserver(
        function () {

          clearTimeout(
            timer
          );


          timer =
            setTimeout(
              function () {

                /*
                 * جلوگیری از پردازش بی‌مورد
                 * هنگام تغییرات داخلی خودمان.
                 */

                processCalculation();

              },
              120
            );
        }
      );


    observer.observe(
      document.body,
      {
        childList: true,
        subtree: true,
        characterData: true
      }
    );
  }


  // ==========================================================
  // Start
  // ==========================================================

  if (
    document.readyState ===
    'loading'
  ) {

    document.addEventListener(
      'DOMContentLoaded',
      function () {

        run();

        observe();

      },
      {
        once: true
      }
    );

  } else {

    run();

    observe();
  }

})();