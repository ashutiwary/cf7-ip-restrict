document.addEventListener("DOMContentLoaded", function () {
  var COOKIE = "cf7_already_submitted";
  var settings = window.cf7IpRestrict || {};
  var REPEAT_ENABLED = String(settings.repeatEnabled) === "1";
  // 0 means a session cookie, so the prompt resets when the browser closes.
  var MAX_AGE_SECONDS = parseInt(settings.repeatMaxAge, 10) || 0;

  var REPEAT_TEXT = "You Already Submitted Form. Do you want to Submit Again?";
  var BLOCK_MESSAGES = {
    "Submission is Blocked": "Your Form Submissions are Permanently Blocked.",
    "Your submission contains inapropriate words":
      "Your submission contains inapropriate words",
  };

  var modal = document.getElementById("cfcustomErrorModal");
  var submitAgainButton = modal && modal.querySelector(".cf-unblock");
  if (!modal || !submitAgainButton) {
    return;
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
    closeModal();
    if (!form) {
      return;
    }

    clearSubmitted();
    if (window.wpcf7 && wpcf7.submit) {
      wpcf7.submit(form);
    } else {
      form.submit();
    }
  });

  // Blocklist and keyword rejections still come back from the server.
  document.addEventListener("wpcf7invalid", function (event) {
    var fields = (event.detail.apiResponse || {}).invalid_fields || [];
    var match = Object.keys(BLOCK_MESSAGES).find(function (known) {
      return fields.some(function (field) {
        return field.message && field.message.indexOf(known) !== -1;
      });
    });

    if (match) {
      openModal(BLOCK_MESSAGES[match], false);
    }
  });
});
