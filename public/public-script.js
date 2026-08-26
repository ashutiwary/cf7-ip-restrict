document.addEventListener("DOMContentLoaded", function () {
  var COOKIE = "cf7_already_submitted";
  var settings = window.cf7IpRestrict || {};
  var REPEAT_ENABLED = String(settings.repeatEnabled) === "1";
  // 0 means a session cookie, so the prompt resets when the browser closes.
  var MAX_AGE_SECONDS = parseInt(settings.repeatMaxAge, 10) || 0;

  var CONFIRM_FIELD = "cf7-ip-restrict-confirm";
  var CAPTCHA_FIELD = "cf7-ip-restrict-captcha";
  var CAPTCHA_KEY = settings.captchaKey || "";
  var REPEAT_TEXT = "You Already Submitted Form. Do you want to Submit Again?";
  var MESSAGES = {
    ip: "We're unable to accept your submission at this time. Please contact us directly if you need assistance.",
    keyword: "Your submission contains inapropriate words",
    repeat: REPEAT_TEXT,
    captcha: "Captcha check failed. Please tick the box and try again.",
  };
  var SHOWS_SUBMIT_AGAIN = { repeat: true, captcha: true };

  var modal = document.getElementById("cfcustomErrorModal");
  var submitAgainButton = modal && modal.querySelector(".cf-unblock");
  if (!modal || !submitAgainButton) {
    return;
  }

  var captchaBox = document.getElementById("cf-captcha-box");
  var widgetId = null;

  // Rendered on first open rather than up front: reCAPTCHA measures its
  // container, and the modal is display:none until the visitor needs it.
  // ponytail: polls for the API instead of using its onload callback, which
  // would need our globals defined before Google's script runs. Give up after
  // 5s, leaving the button disabled, which is what the server would enforce.
  function renderCaptcha(tries) {
    if (widgetId !== null || !captchaBox || !CAPTCHA_KEY) {
      return;
    }
    if (!window.grecaptcha || !grecaptcha.render) {
      if (tries < 25) {
        setTimeout(function () {
          renderCaptcha(tries + 1);
        }, 200);
      }
      return;
    }

    widgetId = grecaptcha.render(captchaBox, {
      sitekey: CAPTCHA_KEY,
      callback: function () {
        submitAgainButton.disabled = false;
      },
      "expired-callback": function () {
        submitAgainButton.disabled = true;
      },
      "error-callback": function () {
        submitAgainButton.disabled = true;
      },
    });
  }

  function captchaRequired() {
    return !!(CAPTCHA_KEY && captchaBox);
  }

  // The form waiting on the visitor's answer.
  var pendingForm = null;

  function hasSubmitted() {
    return document.cookie.split(";").some(function (part) {
      return part.trim() === COOKIE + "=1";
    });
  }

  function markSubmitted() {
    var cookie = COOKIE + "=1; path=/; SameSite=Lax";
    if (MAX_AGE_SECONDS) {
      cookie += "; max-age=" + MAX_AGE_SECONDS;
    }
    if (location.protocol === "https:") {
      cookie += "; Secure";
    }
    document.cookie = cookie;
  }

  function clearSubmitted() {
    document.cookie = COOKIE + "=; path=/; max-age=0";
  }

  function openModal(text, showSubmitAgain) {
    modal.querySelector(".cf-modal-body").innerText = text;
    submitAgainButton.style.display = showSubmitAgain ? "" : "none";

    // The button stays visible either way, it just cannot be pressed until the
    // tickbox is solved. A stale token from a previous open is cleared first.
    if (showSubmitAgain && captchaRequired()) {
      captchaBox.hidden = false;
      submitAgainButton.disabled = true;
      if (widgetId === null) {
        renderCaptcha(0);
      } else {
        grecaptcha.reset(widgetId);
      }
    } else if (captchaBox) {
      captchaBox.hidden = true;
      submitAgainButton.disabled = false;
    }

    modal.style.display = "block";
  }

  function closeModal() {
    modal.style.display = "none";
    pendingForm = null;
  }

  modal.querySelectorAll(".cf-close-custom").forEach(function (button) {
    button.addEventListener("click", closeModal);
  });

  // Capture phase on the document runs before CF7's own submit handler, so the
  // submission is stopped before it is sent. Nothing is posted, no error exists.
  if (REPEAT_ENABLED) {
    document.addEventListener(
      "submit",
      function (event) {
        var form = event.target;
        if (!form.classList || !form.classList.contains("wpcf7-form")) {
          return;
        }
        if (!hasSubmitted()) {
          return;
        }

        event.preventDefault();
        event.stopPropagation();
        pendingForm = form;
        openModal(REPEAT_TEXT, true);
      },
      true
    );

    // A completed submission arms the prompt for the next one, on any page.
    document.addEventListener("wpcf7mailsent", markSubmitted);
  }

  // Submit Again: hand the same form back to CF7 to submit normally.
  submitAgainButton.addEventListener("click", function () {
    var form = pendingForm;

    // The widget lives in the footer modal, outside the form, so its token has
    // to be carried over by hand rather than posted with the rest of the fields.
    var token = captchaRequired() && widgetId !== null ? grecaptcha.getResponse(widgetId) : "";
    if (captchaRequired() && !token) {
      return;
    }

    closeModal();
    if (!form) {
      return;
    }

    clearSubmitted();

    var extra = [makeField(CONFIRM_FIELD, "1")];
    if (token) {
      extra.push(makeField(CAPTCHA_FIELD, token));
    }
    extra.forEach(function (field) {
      form.appendChild(field);
    });

    if (window.wpcf7 && wpcf7.submit) {
      wpcf7.submit(form);
    } else {
      form.submit();
    }
    extra.forEach(function (field) {
      field.remove();
    });
  });

  function makeField(name, value) {
    var field = document.createElement("input");
    field.type = "hidden";
    field.name = name;
    field.value = value;
    return field;
  }

  function showBlock(event) {
    var reason = (event.detail.apiResponse || {}).cf7_ip_restrict;
    if (reason && MESSAGES[reason]) {
      pendingForm = event.target;
      openModal(MESSAGES[reason], !!SHOWS_SUBMIT_AGAIN[reason]);
    }
  }

  // Both fire for a rejected submission depending on CF7 version; opening the
  // same modal twice is harmless, missing it entirely would not be.
  document.addEventListener("wpcf7submit", showBlock);
  document.addEventListener("wpcf7invalid", showBlock);
});
