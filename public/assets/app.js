(() => {
  const state = window.__FCC_STATE__ || {
    locale: "en-US",
    rtl: false,
    supportedLocales: ["en-US"],
    messages: {},
    csrfToken: "",
    user: null,
    apiBase: "/api/v1",
  };

  const root = document.getElementById("app");
  let currentScan = null;
  let pollTimer = null;
  let updaterStatus = null;
  let updaterStatusLoading = false;

  const t = (key, fallback) => state.messages[key] || fallback || key;

  const api = async (method, path, body, extraHeaders = {}) => {
    const headers = {
      Accept: "application/json",
      ...extraHeaders,
    };

    if (body !== undefined) {
      headers["Content-Type"] = "application/json";
    }

    if (["POST", "PUT", "PATCH", "DELETE"].includes(method)) {
      headers["X-CSRF-Token"] = state.csrfToken;
    }

    const response = await fetch(`${state.apiBase}${path}`, {
      method,
      headers,
      credentials: "same-origin",
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const json = await response.json().catch(() => null);
    if (!response.ok || !json || json.success !== true) {
      const error = json?.error?.message || `HTTP ${response.status}`;
      throw new Error(error);
    }

    return json;
  };

  const clear = (el) => {
    while (el.firstChild) {
      el.removeChild(el.firstChild);
    }
  };

  const create = (tag, className, text, attrs = {}) => {
    const el = document.createElement(tag);
    if (className) {
      el.className = className;
    }
    if (text !== undefined) {
      el.textContent = text;
    }
    Object.keys(attrs).forEach(k => el.setAttribute(k, attrs[k]));
    return el;
  };

  const notify = (message, type = "success") => {
    const host = document.getElementById("noticeHost");
    if (!host) {
      return;
    }

    const role = ["error", "warning"].includes(type) ? "alert" : "status";
    const icons = { success: "✅", error: "❌", warning: "⚠️", info: "ℹ️" };
    const icon = icons[type] || icons.info;

    const box = create("div", `notice ${type}`);
    box.setAttribute("role", role);
    // Inline styles for layout
    box.style.display = "flex";
    box.style.alignItems = "center";
    box.style.justifyContent = "space-between";
    box.style.gap = "10px";

    const content = create("div", "", undefined);
    content.style.display = "flex";
    content.style.alignItems = "center";
    content.style.gap = "8px";

    const iconSpan = create("span", "", icon);
    iconSpan.setAttribute("aria-hidden", "true");
    content.appendChild(iconSpan);

    const textSpan = create("span", "", message);
    content.appendChild(textSpan);
    box.appendChild(content);

    const closeBtn = create("button", "", "×");
    closeBtn.type = "button";
    closeBtn.setAttribute("aria-label", t("action.close", "Close"));

    // Inline styles for close button
    closeBtn.style.background = "transparent";
    closeBtn.style.border = "none";
    closeBtn.style.color = "inherit";
    closeBtn.style.fontSize = "1.5em";
    closeBtn.style.lineHeight = "1";
    closeBtn.style.padding = "0";
    closeBtn.style.cursor = "pointer";
    closeBtn.style.opacity = "0.6";

    const handleCloseBtnActivate = () => {
      if (box.parentNode === host) {
        host.removeChild(box);
      }
    };
    const handleCloseBtnHighlightOn = () => { closeBtn.style.opacity = "1"; };
    const handleCloseBtnHighlightOff = () => { closeBtn.style.opacity = "0.6"; };

    closeBtn.addEventListener("mouseover", handleCloseBtnHighlightOn);
    closeBtn.addEventListener("mouseout", handleCloseBtnHighlightOff);
    closeBtn.addEventListener("focus", handleCloseBtnHighlightOn);
    closeBtn.addEventListener("blur", handleCloseBtnHighlightOff);
    closeBtn.addEventListener("click", handleCloseBtnActivate);
    box.appendChild(closeBtn);

    clear(host);
    host.appendChild(box);

    // Auto-dismiss success messages
    if (type === "success") {
      setTimeout(() => {
        if (box.parentNode === host) {
          host.removeChild(box);
        }
      }, 5000);
    }
  };

  const statusBadge = (status) => {
    const normalized = ["completed", "partial", "failed", "cancelled"].includes(status)
      ? status
      : "failed";
    const badge = create("span", `badge ${normalized}`, normalized);
    return badge;
  };

  const sanitizeKeywordList = (raw) => {
    return raw
      .split(",")
      .map((x) => x.trim())
      .filter(Boolean);
  };

  const deterministicWorkerPool = async (items, worker, concurrency) => {
    const safeConcurrency = Math.max(1, Number(concurrency || 1));
    const queue = [...items];
    const output = [];

    const runners = Array.from({ length: Math.min(safeConcurrency, queue.length) }).map(async () => {
      while (queue.length > 0) {
        const item = queue.shift();
        if (item === undefined) {
          continue;
        }
        try {
          const value = await worker(item);
          output.push({ status: "fulfilled", value, item });
        } catch (error) {
          output.push({ status: "rejected", reason: error, item });
        }
      }
    });

    await Promise.all(runners);
    return output;
  };

  const hasRole = (role) => {
    return Boolean(state.user && Array.isArray(state.user.roles) && state.user.roles.includes(role));
  };

  const isAdminUser = () => hasRole("admin");

  const formatTimestamp = (value) => {
    if (!value) {
      return "-";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return String(value);
    }

    return date.toLocaleString();
  };

  const refreshUpdaterStatus = async (silent = true) => {
    if (!isAdminUser()) {
      updaterStatus = null;
      return null;
    }

    updaterStatusLoading = true;
    try {
      const res = await api("GET", "/updates/status");
      updaterStatus = res?.data?.update || null;
      return updaterStatus;
    } catch (error) {
      if (!silent) {
        notify(error.message || String(error), "error");
      }
      throw error;
    } finally {
      updaterStatusLoading = false;
    }
  };

  const checkForUpdatesAction = async (force = false) => {
    try {
      const res = await api("POST", "/updates/check", { force });
      updaterStatus = res?.data?.update || updaterStatus;
      notify(t("update.check_success", "Update check completed."), "success");
      render();
    } catch (error) {
      notify(error.message || String(error), "error");
    }
  };

  const approveUpdateAction = async () => {
    const version = updaterStatus?.latestVersion || "";
    if (!version) {
      notify(t("update.no_pending", "No pending update found."), "warning");
      return;
    }

    try {
      const res = await api("POST", "/updates/approve", { version });
      updaterStatus = res?.data?.update || updaterStatus;
      notify(t("update.approve_success", "Update approved."), "success");
      render();
    } catch (error) {
      notify(error.message || String(error), "error");
    }
  };

  const revokeApprovalAction = async () => {
    const version = updaterStatus?.approvedVersion || "";
    try {
      const payload = version ? { version } : {};
      const res = await api("POST", "/updates/revoke-approval", payload);
      updaterStatus = res?.data?.update || updaterStatus;
      notify(t("update.revoke_success", "Approval revoked."), "success");
      render();
    } catch (error) {
      notify(error.message || String(error), "error");
    }
  };

  const renderResultsTable = (rows) => {
    const container = document.getElementById("resultTableHost");
    clear(container);

    const wrap = create("div", "table-wrap");
    const table = create("table");
    const thead = document.createElement("thead");
    const headRow = document.createElement("tr");

    [
      t("table.target", "Target"),
      t("table.status", "Status"),
      t("table.keyword", "Keyword"),
      t("table.title", "Title"),
      t("table.url", "URL"),
      t("table.severity", "Severity"),
      t("table.source", "Source"),
    ].forEach((label) => {
      const th = create("th", "", label);
      th.scope = "col";
      headRow.appendChild(th);
    });

    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = document.createElement("tbody");

    if (!rows.length) {
      const tr = document.createElement("tr");
      const td = create("td", "", t("scan.no_results", "No results found."));
      td.colSpan = 7;
      tr.appendChild(td);
      tbody.appendChild(tr);
    } else {
      rows.forEach((row) => {
        const tr = document.createElement("tr");

        const targetTd = create("td", "mono", row.target || "-");
        const statusTd = create("td");
        statusTd.appendChild(statusBadge(row.status || "failed"));

        const keywordTd = create("td", "mono", row.keyword || "-");
        const titleTd = create("td", "", row.title || "-");

        const linkTd = create("td");
        if (row.url) {
          const a = create("a", "mono", row.url);
          a.href = row.url;
          a.target = "_blank";
          a.rel = "noopener noreferrer";
          linkTd.appendChild(a);
        } else {
          linkTd.textContent = "-";
        }

        const severityTd = create("td", "mono", String(row.severity ?? "-"));
        const sourceTd = create("td", "mono", row.source || "-");

        [targetTd, statusTd, keywordTd, titleTd, linkTd, severityTd, sourceTd].forEach((td) => tr.appendChild(td));
        tbody.appendChild(tr);
      });
    }

    table.appendChild(tbody);
    wrap.appendChild(table);
    container.appendChild(wrap);
  };

  const summarize = (scan, rows) => {
    const stats = {
      status: scan.status || "unknown",
      targets: Number(scan.target_count || 0),
      completed: Number(scan.completed_count || 0),
      partial: Number(scan.partial_count || 0),
      failed: Number(scan.failed_count || 0),
      cancelled: Number(scan.cancelled_count || 0),
      matches: Number(scan.match_count || 0),
    };

    const host = document.getElementById("statsHost");
    clear(host);

    const cards = [
      [t("stats.status", "Status"), stats.status],
      [t("stats.targets", "Targets"), String(stats.targets)],
      [t("stats.matches", "Matches"), String(stats.matches)],
      [t("stats.completed", "Completed"), String(stats.completed)],
      [t("stats.failed", "Failed"), String(stats.failed + stats.partial + stats.cancelled)],
    ];

    const grid = create("div", "stats");
    cards.forEach(([label, value]) => {
      const card = create("div", "stat");
      const l = create("div", "label", label);
      const v = create("div", "value", value);
      card.appendChild(l);
      card.appendChild(v);
      grid.appendChild(card);
    });

    host.appendChild(grid);

    const mergedRows = [];
    const byResult = new Map();
    rows.forEach((entry) => {
      const key = `${entry.id}`;
      if (!byResult.has(key)) {
        byResult.set(key, {
          target: entry.target,
          status: entry.status,
          keyword: entry.keyword,
          title: entry.title,
          url: entry.url,
          severity: entry.severity,
          source: entry.source,
        });
      } else {
        byResult.set(`${entry.id}-${entry.keyword}-${entry.url}`, {
          target: entry.target,
          status: entry.status,
          keyword: entry.keyword,
          title: entry.title,
          url: entry.url,
          severity: entry.severity,
          source: entry.source,
        });
      }
    });

    byResult.forEach((value) => mergedRows.push(value));
    renderResultsTable(mergedRows.filter((r) => r.keyword || r.url || r.title || r.status));
  };

  const refreshScan = async (scanId) => {
    const scanRes = await api("GET", `/scans/${scanId}`);
    const resultRes = await api("GET", `/scans/${scanId}/results`);
    const scan = scanRes.data.scan;
    const rows = resultRes.data.results || [];

    currentScan = scan;
    summarize(scan, rows);

    const done = ["completed", "partial", "failed", "cancelled"].includes(scan.status);
    if (done && pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
      notify(t("scan.finished", "Scan finished."), scan.status === "completed" ? "success" : "warning");
    }

    const progress = document.getElementById("scanProgress");
    const checked = Number(scan.completed_count || 0) + Number(scan.partial_count || 0) + Number(scan.failed_count || 0) + Number(scan.cancelled_count || 0);
    const total = Math.max(1, Number(scan.target_count || 1));
    const pct = Math.min(100, Math.round((checked / total) * 100));
    progress.value = pct;
    document.getElementById("progressText").textContent = `${checked}/${total} (${pct}%)`;

    const exportHost = document.getElementById("exportHost");
    clear(exportHost);
    if (done) {
      ["csv", "json", "xlsx", "pdf"].forEach((fmt) => {
        const btn = create("button", "secondary", fmt.toUpperCase());
        btn.addEventListener("click", () => {
          window.open(`${state.apiBase}/reports/${scan.id}.${fmt}`, "_blank");
        });
        exportHost.appendChild(btn);
      });
    }
  };

  const startScan = async (event) => {
    event.preventDefault();
    try {
      const targets = document
        .getElementById("targets")
        .value.split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);

      const keywords = sanitizeKeywordList(document.getElementById("keywords").value || "casino");
      const excludeKeywords = sanitizeKeywordList(document.getElementById("excludeKeywords").value || "");
      const keywordMode = document.getElementById("keywordMode").value;
      const exactMatch = document.getElementById("exactMatch").checked;
      const sync = document.getElementById("syncMode").checked;

      if (!targets.length) {
        notify(t("scan.target_required", "Please enter at least one target."), "warning");
        return;
      }

      notify(t("scan.started", "Scan started."), "success");

      const response = await api("POST", "/scans", {
        targets,
        keywords,
        excludeKeywords,
        keywordMode,
        exactMatch,
        sync,
      });

      const scan = response.data.scan;
      await refreshScan(scan.id);

      if (!["completed", "partial", "failed", "cancelled"].includes(scan.status)) {
        if (pollTimer) {
          clearInterval(pollTimer);
        }
        pollTimer = setInterval(() => {
          refreshScan(scan.id).catch((e) => notify(e.message, "error"));
        }, 2500);
      }
    } catch (error) {
      notify(error.message || String(error), "error");
    }
  };

  const loadLocales = async (newLocale) => {
    try {
      const path = `/locales${newLocale ? `?lang=${encodeURIComponent(newLocale)}` : ""}`;
      const response = await api("GET", path);
      const data = response.data;
      state.locale = data.current;
      state.messages = data.messages || state.messages;
      state.rtl = Boolean(data.rtl);
      document.documentElement.lang = state.locale;
      document.documentElement.dir = state.rtl ? "rtl" : "ltr";
      render();
    } catch (error) {
      notify(error.message || String(error), "error");
    }
  };

  const login = async (event) => {
    event.preventDefault();
    const email = document.getElementById("loginEmail").value.trim();
    const password = document.getElementById("loginPassword").value;
    const otpCode = document.getElementById("loginOtp").value.trim();

    try {
      const res = await api("POST", "/auth/login", {
        email,
        password,
        otpCode: otpCode || null,
      });
      state.user = res.data.user;
      state.csrfToken = res.data.csrfToken;
      updaterStatus = null;
      updaterStatusLoading = false;
      if (isAdminUser()) {
        await refreshUpdaterStatus(true).catch(() => {});
      }
      notify(t("auth.logged_in", "Logged in."), "success");
      render();
    } catch (error) {
      notify(error.message || String(error), "error");
    }
  };

  const logout = async () => {
    try {
      await api("POST", "/auth/logout", {});
      state.user = null;
      state.csrfToken = "";
      currentScan = null;
      updaterStatus = null;
      updaterStatusLoading = false;
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
      render();
    } catch (error) {
      notify(error.message || String(error), "error");
    }
  };

  const issueToken = async () => {
    const name = prompt(t("token.name_prompt", "Token name"), "automation-token");
    if (!name) {
      return;
    }

    try {
      const res = await api("POST", "/auth/tokens", {
        name,
        scope: "scan:read scan:write report:read",
      });
      const value = res.data.token;
      navigator.clipboard?.writeText(value).catch(() => {});
      notify(t("token.issued", "Token issued and copied to clipboard."), "success");
    } catch (error) {
      notify(error.message || String(error), "error");
    }
  };

  const runLegacyBatch = async () => {
    const targets = document
      .getElementById("targets")
      .value.split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean);
    const keywords = document.getElementById("keywords").value;

    if (!targets.length) {
      notify(t("scan.target_required", "Please enter at least one target."), "warning");
      return;
    }

    const progress = document.getElementById("scanProgress");
    progress.value = 0;

    let completed = 0;
    const total = targets.length;

    const results = await deterministicWorkerPool(
      targets,
      async (target) => {
        const response = await fetch("/forbidden_checker.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify({ domain: target, extraKeywords: keywords }),
        });
        const data = await response.json();
        completed += 1;
        progress.value = Math.round((completed / total) * 100);
        document.getElementById("progressText").textContent = `${completed}/${total}`;
        return data;
      },
      5
    );

    const rows = [];
    results.forEach((item) => {
      if (item.status === "fulfilled") {
        const data = item.value;
        const status = data.status === "completed" ? "completed" : "failed";
        (data.casinoResults || []).forEach((tuple) => {
          rows.push({
            target: data.domain,
            status,
            keyword: tuple[2] || "casino",
            title: tuple[0],
            url: tuple[1],
            severity: 70,
            source: "legacy",
          });
        });
        (data.extraKeywordResults || []).forEach((tuple) => {
          rows.push({
            target: data.domain,
            status,
            keyword: tuple[2] || "keyword",
            title: tuple[0],
            url: tuple[1],
            severity: 65,
            source: "legacy",
          });
        });
      } else {
        rows.push({
          target: String(item.item),
          status: "failed",
          keyword: "-",
          title: String(item.reason?.message || "Legacy scan failed"),
          url: "",
          severity: 0,
          source: "legacy",
        });
      }
    });

    summarize(
      {
        status: "completed",
        target_count: targets.length,
        completed_count: targets.length,
        partial_count: 0,
        failed_count: 0,
        cancelled_count: 0,
        match_count: rows.length,
      },
      rows.map((row, idx) => ({ id: idx + 1, ...row }))
    );

    notify(t("scan.legacy_done", "Legacy batch run completed."), "success");
  };

  const authPanel = () => {
    const card = create("section", "card");
    const h = create("h2", "title", t("auth.title", "Authentication"));
    card.appendChild(h);

    const form = create("form", "grid-3", undefined, { id: "loginForm" });
    form.innerHTML = `
      <div>
        <label for="loginEmail">${t("auth.email", "Email")}</label>
        <input id="loginEmail" type="email" placeholder="admin@example.com" required>
      </div>
      <div>
        <label for="loginPassword">${t("auth.password", "Password")}</label>
        <input id="loginPassword" type="password" placeholder="••••••••" required>
      </div>
      <div>
        <label for="loginOtp">${t("auth.otp", "MFA Code (optional)")}</label>
        <input id="loginOtp" type="text" inputmode="numeric" maxlength="6" placeholder="123456">
      </div>
    `;

    const actions = create("div", "controls");
    const submit = create("button", "", t("auth.login", "Login"), { form: "loginForm", type: "submit" });
    actions.appendChild(submit);

    form.addEventListener("submit", login);
    card.appendChild(form);
    card.appendChild(actions);
    return card;
  };

  const updaterPanel = () => {
    const card = create("section", "card");
    card.appendChild(create("h2", "title", t("update.title", "Software Updates")));
    card.appendChild(create("p", "subtitle", t("update.subtitle", "Check GitHub releases and approve safe updates for CLI apply.")));

    if (updaterStatusLoading && updaterStatus === null) {
      card.appendChild(create("div", "mono", t("update.loading", "Loading update status...")));
      return card;
    }

    const status = updaterStatus || {};
    const statsGrid = create("div", "stats");
    const cards = [
      [t("update.installed", "Installed"), status.installedVersion || "-"],
      [t("update.latest", "Latest"), status.latestVersion || "-"],
      [t("update.status", "Status"), status.status || "idle"],
      [t("update.last_check", "Last Check"), formatTimestamp(status.lastCheckAt)],
      [t("update.last_apply", "Last Apply"), formatTimestamp(status.lastApplyAt)],
    ];

    cards.forEach(([label, value]) => {
      const stat = create("div", "stat");
      stat.appendChild(create("div", "label", label));
      stat.appendChild(create("div", "value", String(value)));
      statsGrid.appendChild(stat);
    });

    card.appendChild(statsGrid);

    const meta = create("div", "shell");
    meta.appendChild(create("div", "mono", `${t("update.approved", "Approved")}: ${status.approvedVersion || "-"}`));
    meta.appendChild(create("div", "mono", `${t("update.transport", "Last Transport")}: ${status.lastTransport || "-"}`));
    meta.appendChild(create("div", "mono", `${t("update.error", "Last Error")}: ${status.lastError || "-"}`));
    card.appendChild(meta);

    const controls = create("div", "controls");
    const checkBtn = create("button", "secondary", t("update.check", "Check for updates"));
    checkBtn.type = "button";
    checkBtn.addEventListener("click", () => checkForUpdatesAction(true));

    const approveBtn = create("button", "", t("update.approve", "Approve update"));
    approveBtn.type = "button";
    const canApprove = Boolean(status.latestVersion && status.status === "update_available");
    approveBtn.disabled = !canApprove;
    approveBtn.addEventListener("click", approveUpdateAction);

    const revokeBtn = create("button", "danger", t("update.revoke", "Revoke approval"));
    revokeBtn.type = "button";
    revokeBtn.disabled = !status.approvedVersion;
    revokeBtn.addEventListener("click", revokeApprovalAction);

    controls.appendChild(checkBtn);
    controls.appendChild(approveBtn);
    controls.appendChild(revokeBtn);
    card.appendChild(controls);

    card.appendChild(create("small", "hint", t("update.apply_hint", "Applying updates runs only via CLI/cron: `php bin/updater.php --apply-approved`.")));
    return card;
  };

  const appPanel = () => {
    const shell = create("div", "shell");

    const top = create("section", "card");
    const header = create("div", "header");
    const left = create("div");
    left.appendChild(create("h1", "title", t("app.title", "Forbidden Content Checker v3")));
    left.appendChild(create("p", "subtitle", t("app.subtitle", "WordPress-first scanning with multilingual UI, queueing, and secure exports.")));

    const right = create("div", "controls");
    const localeSelect = create("select");
    localeSelect.setAttribute("aria-label", t("app.locale_select", "Select language"));
    state.supportedLocales.forEach((code) => {
      const opt = create("option", "", code);
      opt.value = code;
      if (code === state.locale) {
        opt.selected = true;
      }
      localeSelect.appendChild(opt);
    });
    localeSelect.addEventListener("change", (event) => loadLocales(event.target.value));

    const tokenBtn = create("button", "secondary", t("token.issue", "Issue API Token"));
    tokenBtn.addEventListener("click", issueToken);

    const logoutBtn = create("button", "danger", t("auth.logout", "Logout"));
    logoutBtn.addEventListener("click", logout);

    right.appendChild(localeSelect);
    right.appendChild(tokenBtn);
    right.appendChild(logoutBtn);

    header.appendChild(left);
    header.appendChild(right);
    top.appendChild(header);
    top.appendChild(create("div", "", ""));

    const noticeHost = create("div");
    noticeHost.id = "noticeHost";
    noticeHost.setAttribute("role", "status");
    noticeHost.setAttribute("aria-live", "polite");
    top.appendChild(noticeHost);

    shell.appendChild(top);

    if (isAdminUser()) {
      shell.appendChild(updaterPanel());
    }

    const formCard = create("section", "card");
    const form = create("form", "shell");

    const gridA = create("div", "grid-2");

    const targetsBlock = create("div");
    targetsBlock.appendChild(create("label", "", t("scan.targets", "Targets (one per line)"), { for: "targets" }));
    const targets = create("textarea");
    targets.id = "targets";
    targets.rows = 7;
    targets.placeholder = "example.com\nhttps://news.example.org";
    targetsBlock.appendChild(targets);
    targetsBlock.appendChild(create("small", "hint", t("scan.targets_hint", "Domains or URLs. SSRF-safe validation is enforced.")));

    const keywordsBlock = create("div", "shell");

    const keywordsWrap = create("div");
    keywordsWrap.appendChild(create("label", "", t("scan.keywords", "Keywords"), { for: "keywords" }));
    const keywords = create("input");
    keywords.id = "keywords";
    keywords.value = "casino";
    keywords.placeholder = "casino, betting, slot";
    keywordsWrap.appendChild(keywords);

    const excludesWrap = create("div");
    excludesWrap.appendChild(create("label", "", t("scan.exclude_keywords", "Exclude Keywords"), { for: "excludeKeywords" }));
    const excludeKeywords = create("input");
    excludeKeywords.id = "excludeKeywords";
    excludeKeywords.placeholder = "example: test, demo";
    excludesWrap.appendChild(excludeKeywords);

    const optionsGrid = create("div", "grid-2");

    const modeWrap = create("div");
    modeWrap.appendChild(create("label", "", t("scan.keyword_mode", "Keyword Mode"), { for: "keywordMode" }));
    const mode = create("select");
    mode.id = "keywordMode";
    [
      ["exact", t("scan.mode_exact", "Exact/contains")],
      ["regex", t("scan.mode_regex", "Regex")],
    ].forEach(([value, label]) => {
      const opt = create("option", "", label);
      opt.value = value;
      mode.appendChild(opt);
    });
    modeWrap.appendChild(mode);

    const toggles = create("div", "shell");
    const exactWrap = create("label", "", "");
    const exact = create("input");
    exact.id = "exactMatch";
    exact.type = "checkbox";
    exactWrap.appendChild(exact);
    exactWrap.appendChild(document.createTextNode(" " + t("scan.exact_match", "Strict exact title match")));

    const syncWrap = create("label", "", "");
    const sync = create("input");
    sync.id = "syncMode";
    sync.type = "checkbox";
    syncWrap.appendChild(sync);
    syncWrap.appendChild(document.createTextNode(" " + t("scan.sync", "Run synchronously (small batches)")));

    toggles.appendChild(exactWrap);
    toggles.appendChild(syncWrap);

    optionsGrid.appendChild(modeWrap);
    optionsGrid.appendChild(toggles);

    keywordsBlock.appendChild(keywordsWrap);
    keywordsBlock.appendChild(excludesWrap);
    keywordsBlock.appendChild(optionsGrid);

    gridA.appendChild(targetsBlock);
    gridA.appendChild(keywordsBlock);

    form.appendChild(gridA);

    const actionRow = create("div", "controls");
    const startBtn = create("button", "", t("scan.start", "Start Scan"));
    startBtn.type = "submit";

    const legacyBtn = create("button", "secondary", t("scan.legacy_run", "Run Legacy Worker Pool"));
    legacyBtn.type = "button";
    legacyBtn.addEventListener("click", runLegacyBatch);

    actionRow.appendChild(startBtn);
    actionRow.appendChild(legacyBtn);

    form.appendChild(actionRow);

    const progress = create("progress", "progress");
    progress.id = "scanProgress";
    progress.max = 100;
    progress.value = 0;
    progress.setAttribute("aria-label", t("scan.progress", "Scan progress"));
    form.appendChild(progress);

    const progressText = create("div", "mono", "0/0");
    progressText.id = "progressText";
    form.appendChild(progressText);

    form.addEventListener("submit", startScan);

    formCard.appendChild(form);
    shell.appendChild(formCard);

    const statsCard = create("section", "card");
    const statsTitle = create("h2", "title", t("stats.title", "Scan Summary"));
    statsCard.appendChild(statsTitle);

    const statsHost = create("div");
    statsHost.id = "statsHost";
    statsCard.appendChild(statsHost);

    const exportTitle = create("h3", "title", t("report.exports", "Exports"));
    exportTitle.style.fontSize = "1rem";
    statsCard.appendChild(exportTitle);

    const exportHost = create("div", "controls");
    exportHost.id = "exportHost";
    statsCard.appendChild(exportHost);

    shell.appendChild(statsCard);

    const tableCard = create("section", "card");
    tableCard.appendChild(create("h2", "title", t("table.title", "Findings")));
    const tableHost = create("div");
    tableHost.id = "resultTableHost";
    tableCard.appendChild(tableHost);
    shell.appendChild(tableCard);

    return shell;
  };

  const render = () => {
    clear(root);

    if (!state.user) {
      updaterStatus = null;
      updaterStatusLoading = false;
      const wrapper = create("div", "shell");
      const header = create("section", "card");
      header.appendChild(create("h1", "title", t("app.title", "Forbidden Content Checker v3")));
      header.appendChild(create("p", "subtitle", t("auth.required", "Please authenticate to continue.")));
      const localeRow = create("div", "controls");
      const localeSelect = create("select");
      localeSelect.setAttribute("aria-label", t("app.locale_select", "Select language"));
      state.supportedLocales.forEach((code) => {
        const opt = create("option", "", code);
        opt.value = code;
        if (code === state.locale) {
          opt.selected = true;
        }
        localeSelect.appendChild(opt);
      });
      localeSelect.addEventListener("change", (event) => loadLocales(event.target.value));
      localeRow.appendChild(localeSelect);
      header.appendChild(localeRow);
      const noticeHost = create("div");
      noticeHost.id = "noticeHost";
      noticeHost.setAttribute("role", "status");
      noticeHost.setAttribute("aria-live", "polite");
      header.appendChild(noticeHost);
      wrapper.appendChild(header);
      wrapper.appendChild(authPanel());
      root.appendChild(wrapper);
      return;
    }

    if (isAdminUser() && updaterStatus === null && !updaterStatusLoading) {
      refreshUpdaterStatus(true)
        .then(() => render())
        .catch((error) => {
          updaterStatus = {
            status: "failed",
            lastError: error?.message || String(error),
          };
          render();
        });
    }

    root.appendChild(appPanel());

    if (currentScan && currentScan.id) {
      refreshScan(currentScan.id).catch((e) => notify(e.message, "error"));
    }
  };

  const bootstrap = async () => {
    if (state.user) {
      try {
        const res = await api("GET", "/me");
        state.user = res.data.user;
        state.csrfToken = res.data.csrfToken;
        if (isAdminUser()) {
          await refreshUpdaterStatus(true).catch(() => {});
        }
      } catch (_error) {
        state.user = null;
        updaterStatus = null;
        updaterStatusLoading = false;
      }
    }

    render();
  };

  bootstrap();
})();
