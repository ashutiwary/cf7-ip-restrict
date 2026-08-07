document.addEventListener("DOMContentLoaded", function () {
  var modal = document.getElementById("cfcustomErrorModal");
  var unblockButton = modal && modal.querySelector(".cf-unblock");
  if (!modal || !unblockButton) {
    return;
  }

  var RATE_LIMIT = "Please wait for 5 Minute";
  var MESSAGES = {
    "Submission is Blocked": "Your Form Submissions are Permanently Blocked.",
    "Your submission contains inapropriate words":
      "Your submission contains inapropriate words",
  };
  MESSAGES[RATE_LIMIT] = "You Already Submitted Form. Do you want to Submit Again?";

  // The form that was actually rejected, so the right one gets resubmitted.
  var blockedForm = null;

  function showCustomModal(errormsg) {
    modal.querySelector(".cf-modal-body").innerText = MESSAGES[errormsg];
    unblockButton.style.display = errormsg === RATE_LIMIT ? "" : "none";
    modal.style.display = "block";
  }

  function hideCustomModal() {
    modal.style.display = "none";
  }

  modal.querySelectorAll(".cf-close-custom").forEach(function (closeButton) {
    closeButton.addEventListener("click", hideCustomModal);
  });

  unblockButton.addEventListener("click", function () {
    if (!blockedForm) {
      hideCustomModal();
      return;
    }

    fetch(ajax_object.ajax_url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: "action=unblock_ip",
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (!data.success) {
          console.error(data.data && data.data.message);
          return;
        }
        if (window.wpcf7 && wpcf7.submit) {
          wpcf7.submit(blockedForm);
        } else {
          blockedForm.submit();
        }
      })
      .catch(function (error) {
        console.error("Error:", error);
      });

    hideCustomModal();
  });

  document.addEventListener(
    "wpcf7invalid",
    function (event) {
      var fields = (event.detail.apiResponse || {}).invalid_fields || [];
      var match = Object.keys(MESSAGES).find(function (known) {
        return fields.some(function (field) {
          return field.message && field.message.indexOf(known) !== -1;
        });
      });

      if (match) {
        blockedForm = event.target;
        showCustomModal(match);
      }
    },
    false
  );
});
