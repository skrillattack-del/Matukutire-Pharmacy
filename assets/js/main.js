/* ==========================================================================
   LOMAGUNDI & FORESTAL PHARMACIES — SITE SCRIPT
   Vanilla JS, no dependencies. Every feature guards for element existence
   so this single file can be shared safely across every page.
   ========================================================================== */
(function () {
  "use strict";

  /* ------------------------------------------------------------------------
     0. Preloader
     ------------------------------------------------------------------------ */
  window.addEventListener("load", function () {
    var pre = document.querySelector(".preloader");
    if (pre) {
      setTimeout(function () {
        pre.classList.add("is-hidden");
      }, 150);
    }
  });

  /* ------------------------------------------------------------------------
     1. Footer year
     ------------------------------------------------------------------------ */
  document.querySelectorAll("[data-year]").forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });

  /* ------------------------------------------------------------------------
     2. Theme toggle (persisted, respects system preference on first visit)
     ------------------------------------------------------------------------ */
  (function themeInit() {
    var root = document.documentElement;
    var stored = null;
    try {
      stored = localStorage.getItem("lfp-theme");
    } catch (e) {
      /* localStorage unavailable (private mode) — fall back silently */
    }
    var prefersDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
    var initial = stored || (prefersDark ? "dark" : "light");
    if (initial === "dark") root.setAttribute("data-theme", "dark");

    document.querySelectorAll("[data-theme-toggle]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var isDark = root.getAttribute("data-theme") === "dark";
        if (isDark) {
          root.removeAttribute("data-theme");
        } else {
          root.setAttribute("data-theme", "dark");
        }
        try {
          localStorage.setItem("lfp-theme", isDark ? "light" : "dark");
        } catch (e) {
          /* ignore */
        }
      });
    });
  })();

  /* ------------------------------------------------------------------------
     3. Sticky header shadow on scroll
     ------------------------------------------------------------------------ */
  var header = document.querySelector(".site-header");
  function onScrollHeader() {
    if (!header) return;
    if (window.scrollY > 12) {
      header.classList.add("is-scrolled");
    } else {
      header.classList.remove("is-scrolled");
    }
  }
  document.addEventListener("scroll", onScrollHeader, { passive: true });
  onScrollHeader();

  /* ------------------------------------------------------------------------
     4. Mobile nav toggle
     ------------------------------------------------------------------------ */
  var navToggle = document.querySelector("[data-nav-toggle]");
  var navLinks = document.querySelector("[data-nav-links]");
  var navScrim = document.querySelector("[data-nav-scrim]");

  function closeNav() {
    if (!navLinks) return;
    navLinks.classList.remove("is-open");
    if (navScrim) navScrim.classList.remove("is-open");
    if (navToggle) {
      navToggle.setAttribute("aria-expanded", "false");
      navToggle.classList.remove("is-active");
    }
    document.body.style.overflow = "";
  }
  function openNav() {
    if (!navLinks) return;
    navLinks.classList.add("is-open");
    if (navScrim) navScrim.classList.add("is-open");
    if (navToggle) {
      navToggle.setAttribute("aria-expanded", "true");
      navToggle.classList.add("is-active");
    }
    document.body.style.overflow = "hidden";
  }
  if (navToggle && navLinks) {
    navToggle.addEventListener("click", function () {
      var isOpen = navLinks.classList.contains("is-open");
      if (isOpen) {
        closeNav();
      } else {
        openNav();
      }
    });
  }
  if (navScrim) navScrim.addEventListener("click", closeNav);
  document.querySelectorAll("[data-nav-links] a").forEach(function (a) {
    a.addEventListener("click", closeNav);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closeNav();
  });

  /* ------------------------------------------------------------------------
     5. Active nav link (based on current file name)
     ------------------------------------------------------------------------ */
  (function markActive() {
    var path = window.location.pathname.split("/").pop() || "index.html";
    document.querySelectorAll("[data-nav-links] a, .footer-links a").forEach(function (a) {
      var href = (a.getAttribute("href") || "").split("/").pop();
      if (href === path || (path === "" && href === "index.html")) {
        a.classList.add("active");
      }
    });
  })();

  /* ------------------------------------------------------------------------
     6. Reveal-on-scroll (IntersectionObserver)
     ------------------------------------------------------------------------ */
  (function revealInit() {
    var items = document.querySelectorAll(".reveal");
    if (!items.length) return;
    if (!("IntersectionObserver" in window)) {
      items.forEach(function (el) {
        el.classList.add("is-visible");
      });
      return;
    }
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15, rootMargin: "0px 0px -40px 0px" }
    );
    items.forEach(function (el) {
      io.observe(el);
    });
  })();

  /* ------------------------------------------------------------------------
     7. Animated stat counters
     ------------------------------------------------------------------------ */
  (function countersInit() {
    var counters = document.querySelectorAll("[data-count]");
    if (!counters.length) return;

    function animate(el) {
      var target = parseFloat(el.getAttribute("data-count"));
      var suffix = el.getAttribute("data-suffix") || "";
      var decimals = el.getAttribute("data-decimals") ? parseInt(el.getAttribute("data-decimals"), 10) : 0;
      var duration = 1400;
      var start = null;

      function step(ts) {
        if (start === null) start = ts;
        var progress = Math.min((ts - start) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3);
        var value = target * eased;
        el.textContent = value.toFixed(decimals) + suffix;
        if (progress < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    }

    if (!("IntersectionObserver" in window)) {
      counters.forEach(animate);
      return;
    }
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            animate(entry.target);
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.6 }
    );
    counters.forEach(function (el) {
      io.observe(el);
    });
  })();

  /* ------------------------------------------------------------------------
     8. Filter bars (Shop + Gallery) — data-filter-group / data-filter-target
     ------------------------------------------------------------------------ */
  document.querySelectorAll("[data-filter-group]").forEach(function (group) {
    var targetSelector = group.getAttribute("data-filter-group");
    var items = document.querySelectorAll(targetSelector);
    var noResults = document.querySelector(group.getAttribute("data-empty-target") || "");

    group.querySelectorAll(".filter-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        group.querySelectorAll(".filter-btn").forEach(function (b) {
          b.classList.remove("active");
          b.setAttribute("aria-selected", "false");
        });
        btn.classList.add("active");
        btn.setAttribute("aria-selected", "true");
        var value = btn.getAttribute("data-filter");
        var visibleCount = 0;

        items.forEach(function (item) {
          var cats = (item.getAttribute("data-category") || "").split(" ");
          var show = value === "all" || cats.indexOf(value) !== -1;
          if (show) {
            item.style.display = "";
            visibleCount++;
            requestAnimationFrame(function () {
              item.classList.remove("is-hidden-fade");
            });
          } else {
            item.classList.add("is-hidden-fade");
            item.style.display = "none";
          }
        });

        if (noResults) {
          noResults.classList.toggle("is-visible", visibleCount === 0);
        }
      });
    });
  });

  /* ------------------------------------------------------------------------
     9. Lightbox gallery — data-lightbox-group
     ------------------------------------------------------------------------ */
  (function lightboxInit() {
    var triggers = Array.prototype.slice.call(document.querySelectorAll("[data-lightbox]"));
    var lightbox = document.querySelector(".lightbox");
    if (!triggers.length || !lightbox) return;

    var imgEl = lightbox.querySelector("img");
    var captionEl = lightbox.querySelector(".lightbox-caption");
    var currentIndex = 0;

    function openAt(index) {
      currentIndex = (index + triggers.length) % triggers.length;
      var trigger = triggers[currentIndex];
      var full = trigger.getAttribute("data-lightbox") || trigger.querySelector("img").src;
      var caption = trigger.getAttribute("data-caption") || "";
      imgEl.src = full;
      imgEl.alt = caption;
      if (captionEl) captionEl.textContent = caption;
      lightbox.classList.add("is-open");
      document.body.style.overflow = "hidden";
    }
    function close() {
      lightbox.classList.remove("is-open");
      document.body.style.overflow = "";
      imgEl.src = "";
    }

    triggers.forEach(function (trigger, index) {
      trigger.addEventListener("click", function (e) {
        e.preventDefault();
        openAt(index);
      });
    });

    var closeBtn = lightbox.querySelector(".lightbox-close");
    var prevBtn = lightbox.querySelector(".lightbox-nav.prev");
    var nextBtn = lightbox.querySelector(".lightbox-nav.next");
    if (closeBtn) closeBtn.addEventListener("click", close);
    if (prevBtn) prevBtn.addEventListener("click", function () { openAt(currentIndex - 1); });
    if (nextBtn) nextBtn.addEventListener("click", function () { openAt(currentIndex + 1); });

    lightbox.addEventListener("click", function (e) {
      if (e.target === lightbox) close();
    });
    document.addEventListener("keydown", function (e) {
      if (!lightbox.classList.contains("is-open")) return;
      if (e.key === "Escape") close();
      if (e.key === "ArrowRight") openAt(currentIndex + 1);
      if (e.key === "ArrowLeft") openAt(currentIndex - 1);
    });
  })();

  /* ------------------------------------------------------------------------
     10. FAQ accordion
     ------------------------------------------------------------------------ */
  document.querySelectorAll(".faq-item").forEach(function (item) {
    var question = item.querySelector(".faq-question");
    var answer = item.querySelector(".faq-answer");
    if (!question || !answer) return;
    question.addEventListener("click", function () {
      var isOpen = item.classList.contains("is-open");
      item.closest(".faq-list").querySelectorAll(".faq-item").forEach(function (other) {
        other.classList.remove("is-open");
        other.querySelector(".faq-answer").style.maxHeight = null;
        other.querySelector(".faq-question").setAttribute("aria-expanded", "false");
      });
      if (!isOpen) {
        item.classList.add("is-open");
        answer.style.maxHeight = answer.scrollHeight + "px";
        question.setAttribute("aria-expanded", "true");
      }
    });
  });

  /* ------------------------------------------------------------------------
     11. Back-to-top button
     ------------------------------------------------------------------------ */
  var backToTop = document.querySelector(".back-to-top");
  if (backToTop) {
    document.addEventListener(
      "scroll",
      function () {
        backToTop.classList.toggle("is-visible", window.scrollY > 480);
      },
      { passive: true }
    );
    backToTop.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  /* ------------------------------------------------------------------------
     12. Contact form — client validation + progressive enhancement submit
     ------------------------------------------------------------------------ */
  document.querySelectorAll("[data-validate-form]").forEach(function (form) {
    var alertBox = form.querySelector(".form-alert");

    function setError(field, message) {
      var wrap = field.closest(".field");
      if (!wrap) return;
      wrap.classList.add("has-error");
      var msg = wrap.querySelector(".error-msg");
      if (msg) msg.textContent = message;
    }
    function clearError(field) {
      var wrap = field.closest(".field");
      if (!wrap) return;
      wrap.classList.remove("has-error");
    }
    function showAlert(type, message) {
      if (!alertBox) return;
      alertBox.textContent = message;
      alertBox.classList.remove("success", "error");
      alertBox.classList.add(type, "is-visible");
      alertBox.scrollIntoView({ behavior: "smooth", block: "center" });
    }

    function validate() {
      var valid = true;
      form.querySelectorAll("[required]").forEach(function (field) {
        clearError(field);
        var value = field.value.trim();
        if (!value) {
          setError(field, "This field is required.");
          valid = false;
          return;
        }
        if (field.type === "email") {
          var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
          if (!emailPattern.test(value)) {
            setError(field, "Please enter a valid email address.");
            valid = false;
          }
        }
        if (field.hasAttribute("data-phone")) {
          var digits = value.replace(/[^0-9]/g, "");
          if (digits.length < 7) {
            setError(field, "Please enter a valid phone number.");
            valid = false;
          }
        }
      });
      return valid;
    }

    form.querySelectorAll("input, textarea, select").forEach(function (field) {
      field.addEventListener("input", function () {
        clearError(field);
      });
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (alertBox) alertBox.classList.remove("is-visible");

      // Honeypot check — if filled, silently "succeed" without sending anywhere
      var honeypot = form.querySelector('input[name="website"]');
      if (honeypot && honeypot.value) {
        showAlert("success", "Thank you! Your message has been received.");
        form.reset();
        return;
      }

      if (!validate()) {
        showAlert("error", "Please fix the highlighted fields and try again.");
        return;
      }

      var submitBtn = form.querySelector('[type="submit"]');
      var originalLabel = submitBtn ? submitBtn.innerHTML : "";
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = "Sending…";
      }

      var action = form.getAttribute("action");
      var formData = new FormData(form);

      fetch(action, { method: "POST", body: formData, headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("Server responded with an error");
          }
          return response.json().catch(function () {
            return { message: "Thank you! Your message has been sent." };
          });
        })
        .then(function (data) {
          showAlert("success", data.message || "Thank you! Your message has been sent — our team will be in touch shortly.");
          form.reset();
        })
        .catch(function () {
          showAlert(
            "error",
            "We could not reach the server (this page may be running without PHP hosting). Please call or WhatsApp us directly — see the contact details alongside this form."
          );
        })
        .finally(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalLabel;
          }
        });
    });
  });

  /* ------------------------------------------------------------------------
     13. Newsletter form (front-end only demo — no backend wired)
     ------------------------------------------------------------------------ */
  document.querySelectorAll(".newsletter-form").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var input = form.querySelector("input[type='email']");
      if (input && input.value) {
        input.value = "";
        input.placeholder = "Thanks — you're on the list!";
      }
    });
  });

  /* ------------------------------------------------------------------------
     14. AI assistant dock — browser-native OpenRouter chat.

     The user supplies a throwaway OpenRouter key in the UI. It is stored only
     in this browser's localStorage and is never sent to this website/server.
     ------------------------------------------------------------------------ */
  (function chatDock() {
    var dock = document.querySelector("[data-chat-dock]");
    var toggle = document.querySelector("[data-chat-toggle]");
    if (!dock || !toggle) return;

    var closeBtn = dock.querySelector("[data-chat-close]");
    var body = dock.querySelector(".chat-dock-body");
    var note = dock.querySelector(".chat-dock-note");
    var KEY_STORAGE = "matukutire-openrouter-key";
    var MODEL_STORAGE = "matukutire-openrouter-model";
    var API_URL = "https://openrouter.ai/api/v1/chat/completions";
    var messages = [];

    if (!body) return;
    body.innerHTML =
      '<section class="chat-setup" data-chat-setup>' +
        '<div class="chat-setup-icon"><i class="bi bi-key-fill"></i></div>' +
        '<h3>Connect OpenRouter</h3>' +
        '<p>Paste a throwaway API key. It stays in this browser and can be cleared anytime.</p>' +
        '<label for="openrouter-key">OpenRouter API key</label>' +
        '<div class="chat-key-row">' +
          '<input id="openrouter-key" data-chat-key type="password" autocomplete="off" placeholder="sk-or-v1-…" />' +
          '<button data-chat-key-toggle type="button" aria-label="Show API key"><i class="bi bi-eye"></i></button>' +
        '</div>' +
        '<label for="openrouter-model">Model</label>' +
        '<select id="openrouter-model" data-chat-model>' +
          '<option value="openrouter/free">OpenRouter Free Router</option>' +
          '<option value="google/gemini-2.5-flash">Gemini 2.5 Flash</option>' +
          '<option value="anthropic/claude-sonnet-4">Claude Sonnet 4</option>' +
          '<option value="openai/gpt-4.1-mini">GPT-4.1 mini</option>' +
        '</select>' +
        '<button class="btn chat-connect" data-chat-connect type="button">Start assistant</button>' +
        '<p class="chat-setup-error" data-chat-setup-error role="alert"></p>' +
      '</section>' +
      '<section class="chat-conversation" data-chat-conversation hidden>' +
        '<div class="chat-toolbar">' +
          '<span data-chat-model-label></span>' +
          '<button data-chat-settings type="button"><i class="bi bi-gear"></i> API settings</button>' +
        '</div>' +
        '<div class="chat-messages" data-chat-messages aria-live="polite"></div>' +
        '<form class="chat-composer" data-chat-form>' +
          '<label class="sr-only" for="chat-message">Message</label>' +
          '<textarea id="chat-message" data-chat-input rows="1" maxlength="1200" placeholder="Ask about branches, hours or products…" required></textarea>' +
          '<button data-chat-send type="submit" aria-label="Send message"><i class="bi bi-send-fill"></i></button>' +
        '</form>' +
      '</section>';

    if (note) {
      note.textContent =
        "General information only — medicine advice must be confirmed with a pharmacist or doctor. Your API key stays in this browser.";
    }

    var setup = body.querySelector("[data-chat-setup]");
    var conversation = body.querySelector("[data-chat-conversation]");
    var keyInput = body.querySelector("[data-chat-key]");
    var keyToggle = body.querySelector("[data-chat-key-toggle]");
    var modelSelect = body.querySelector("[data-chat-model]");
    var connectBtn = body.querySelector("[data-chat-connect]");
    var setupError = body.querySelector("[data-chat-setup-error]");
    var settingsBtn = body.querySelector("[data-chat-settings]");
    var modelLabel = body.querySelector("[data-chat-model-label]");
    var messageList = body.querySelector("[data-chat-messages]");
    var form = body.querySelector("[data-chat-form]");
    var input = body.querySelector("[data-chat-input]");
    var sendBtn = body.querySelector("[data-chat-send]");

    keyInput.value = localStorage.getItem(KEY_STORAGE) || "";
    modelSelect.value = localStorage.getItem(MODEL_STORAGE) || "openrouter/free";

    function setSetupVisible(visible) {
      setup.hidden = !visible;
      conversation.hidden = visible;
      if (!visible) {
        modelLabel.textContent = modelSelect.options[modelSelect.selectedIndex].text;
        if (!messages.length) {
          addMessage(
            "assistant",
            "Hello! I can help with branch details, opening hours, services and general product information. How can I help?"
          );
        }
        window.setTimeout(function () { input.focus(); }, 50);
      }
    }

    function addMessage(role, text, pending) {
      var item = document.createElement("div");
      item.className = "chat-message chat-message-" + role + (pending ? " is-pending" : "");
      item.textContent = text;
      messageList.appendChild(item);
      messageList.scrollTop = messageList.scrollHeight;
      return item;
    }

    function currentKey() {
      return keyInput.value.trim();
    }

    function saveSettings() {
      var key = currentKey();
      if (!/^sk-or-/i.test(key)) {
        setupError.textContent = "Enter a valid OpenRouter key beginning with sk-or-.";
        keyInput.focus();
        return false;
      }
      setupError.textContent = "";
      localStorage.setItem(KEY_STORAGE, key);
      localStorage.setItem(MODEL_STORAGE, modelSelect.value);
      return true;
    }

    function connect() {
      if (saveSettings()) setSetupVisible(false);
    }

    function openDock() {
      dock.classList.add("is-open");
      dock.removeAttribute("aria-hidden");
      toggle.classList.add("is-active");
      toggle.setAttribute("aria-expanded", "true");
      toggle.setAttribute("aria-label", "Close the pharmacy assistant");
      if (currentKey()) setSetupVisible(false);
      else window.setTimeout(function () { keyInput.focus(); }, 50);
    }

    function closeDock() {
      dock.classList.remove("is-open");
      dock.setAttribute("aria-hidden", "true");
      toggle.classList.remove("is-active");
      toggle.setAttribute("aria-expanded", "false");
      toggle.setAttribute("aria-label", "Open the pharmacy assistant");
      toggle.focus();
    }

    toggle.addEventListener("click", function () {
      if (dock.classList.contains("is-open")) {
        closeDock();
      } else {
        openDock();
      }
    });

    if (closeBtn) closeBtn.addEventListener("click", closeDock);

    connectBtn.addEventListener("click", connect);
    keyInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter") connect();
    });
    keyToggle.addEventListener("click", function () {
      var showing = keyInput.type === "text";
      keyInput.type = showing ? "password" : "text";
      keyToggle.innerHTML = showing ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
      keyToggle.setAttribute("aria-label", showing ? "Show API key" : "Hide API key");
    });
    settingsBtn.addEventListener("click", function () {
      setSetupVisible(true);
      keyInput.focus();
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text || sendBtn.disabled) return;

      input.value = "";
      addMessage("user", text);
      messages.push({ role: "user", content: text });
      var pending = addMessage("assistant", "Thinking…", true);
      sendBtn.disabled = true;

      fetch(API_URL, {
        method: "POST",
        headers: {
          "Authorization": "Bearer " + currentKey(),
          "Content-Type": "application/json",
          "HTTP-Referer": window.location.origin,
          "X-OpenRouter-Title": "Matukutire Pharmacies"
        },
        body: JSON.stringify({
          model: modelSelect.value,
          messages: [
            {
              role: "system",
              content:
                "You are the Matukutire Pharmacies website assistant for Lomagundi Pharmacy in Chinhoyi and Forestal Machipisa Pharmacy in Harare, Zimbabwe. Help with branch information, hours, services and general product questions. Never diagnose, prescribe, claim live stock or replace a pharmacist or doctor. For symptoms, interactions, dosage, pregnancy, emergencies or uncertain medicine advice, direct the user to a qualified pharmacist or medical professional. Keep answers concise and friendly."
            }
          ].concat(messages.slice(-10)),
          temperature: 0.3,
          max_tokens: 500
        })
      })
        .then(function (response) {
          return response.json().then(function (payload) {
            if (!response.ok) {
              throw new Error(
                (payload && payload.error && payload.error.message) ||
                "OpenRouter returned HTTP " + response.status + "."
              );
            }
            return payload;
          });
        })
        .then(function (payload) {
          var answer =
            payload &&
            payload.choices &&
            payload.choices[0] &&
            payload.choices[0].message &&
            payload.choices[0].message.content;
          if (!answer) throw new Error("The model returned an empty response.");
          pending.textContent = answer;
          pending.classList.remove("is-pending");
          messages.push({ role: "assistant", content: answer });
        })
        .catch(function (error) {
          pending.textContent = "I couldn't connect: " + error.message;
          pending.classList.remove("is-pending");
          pending.classList.add("is-error");
        })
        .finally(function () {
          sendBtn.disabled = false;
          input.focus();
        });
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && dock.classList.contains("is-open")) closeDock();
    });
  })();
})();
