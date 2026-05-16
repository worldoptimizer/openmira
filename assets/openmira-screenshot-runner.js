(() => {
  const config = window.openMiraScreenshotRunner || {};
  const htmlToImage = window.htmlToImage;
  const jobsNode = document.getElementById('openmira-runner-jobs');
  const countsNode = document.getElementById('openmira-runner-counts');
  const statusNode = document.getElementById('openmira-runner-status');
  const activeNode = document.getElementById('openmira-runner-active');
  const pauseNode = document.getElementById('openmira-runner-pause');
  const refreshNode = document.getElementById('openmira-runner-refresh');
  const frameWrap = document.getElementById('openmira-runner-frame-wrap');
  const frame = document.getElementById('openmira-runner-frame');

  if (!jobsNode || !countsNode || !statusNode || !pauseNode || !frame || !htmlToImage) {
    return;
  }

  let jobs = [];
  let running = false;
  let pollTimer = 0;
  let heartbeatTimer = 0;
  const storedPause = window.localStorage.getItem('openmiraScreenshotRunnerPaused');
  pauseNode.checked = storedPause === '1';

  const setStatus = (message) => {
    statusNode.textContent = message;
  };

  const apiFetch = async (url, options = {}) => {
    const response = await window.fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce || '',
        ...(options.headers || {}),
      },
    });

    const text = await response.text();
    let data = {};
    if (text) {
      try {
        data = JSON.parse(text);
      } catch (error) {
        data = { message: text };
      }
    }

    if (!response.ok) {
      throw new Error(data.message || `Request failed with HTTP ${response.status}`);
    }

    return data;
  };

  const heartbeat = async () => {
    try {
      await apiFetch(config.heartbeatUrl, { method: 'POST', body: '{}' });
    } catch (error) {
      setStatus(`Heartbeat failed: ${error.message}`);
    }
  };

  const fetchJobs = async () => {
    const url = new URL(config.jobsUrl, window.location.href);
    if (config.singleJobId) {
      url.searchParams.set('job_id', config.singleJobId);
    }
    const data = await apiFetch(url.toString(), { method: 'GET', headers: { 'Content-Type': 'application/json' } });
    jobs = Array.isArray(data.jobs) ? data.jobs : [];
    renderJobs(data.pending_count || 0);
    return jobs;
  };

  const escapeText = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#039;',
    '"': '&quot;',
  }[char]));

  const renderJobs = (pendingCount) => {
    const pending = jobs.filter((job) => job.status === 'pending').length;
    const complete = jobs.filter((job) => job.status === 'complete').length;
    const failed = jobs.filter((job) => job.status === 'failed').length;
    countsNode.textContent = config.singleJobId
      ? `${jobs.length} job shown. ${pending} pending, ${complete} complete, ${failed} failed.`
      : `${pendingCount} pending. Showing ${jobs.length} recent jobs for this user.`;

    if (jobs.length === 0) {
      jobsNode.innerHTML = '<p class="description">No screenshot jobs are visible for this runner.</p>';
      return;
    }

    jobsNode.innerHTML = jobs.map((job) => {
      const title = job.label || job.note || job.target_url || job.job_id;
      const viewport = `${job.viewport_width || 0}×${job.viewport_height || 0}${job.full_page ? ' full page' : ''}`;
      const image = job.image_url
        ? `<img class="openmira-runner-thumb" src="${escapeText(job.image_url)}" alt="Screenshot ${escapeText(job.job_id)}">`
        : '';
      const error = job.error ? `<div class="openmira-runner-job-meta">Error: ${escapeText(job.error)}</div>` : '';
      return `
        <article class="openmira-runner-job" data-status="${escapeText(job.status || 'pending')}">
          <div class="openmira-runner-job-title">
            <strong>${escapeText(title)}</strong>
            <code>${escapeText(job.status || 'pending')}</code>
          </div>
          <div class="openmira-runner-job-meta"><code>${escapeText(job.job_id)}</code> · ${escapeText(viewport)}</div>
          <div class="openmira-runner-job-meta"><a href="${escapeText(job.target_url || '#')}" target="_blank" rel="noreferrer">${escapeText(job.target_url || '')}</a></div>
          ${error}
          ${image}
        </article>
      `;
    }).join('');
  };

  const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

  const waitForFrameLoad = () => new Promise((resolve, reject) => {
    const timeout = window.setTimeout(() => reject(new Error('Timed out waiting for target iframe to load.')), 25000);
    frame.onload = () => {
      window.clearTimeout(timeout);
      resolve();
    };
  });

  const waitForTargetReadiness = async (doc) => {
    if (doc.fonts && doc.fonts.ready) {
      await Promise.race([doc.fonts.ready, wait(5000)]);
    }

    const images = Array.from(doc.images || []);
    await Promise.all(images.map((image) => {
      if (image.complete && image.naturalWidth > 0) {
        return Promise.resolve();
      }
      if (typeof image.decode === 'function') {
        return image.decode().catch(() => undefined);
      }
      return new Promise((resolve) => {
        image.addEventListener('load', resolve, { once: true });
        image.addEventListener('error', resolve, { once: true });
      });
    }));

    await wait(Number(config.settleMs || 700));
  };

  const captureJob = async (job) => {
    const width = Math.max(320, Number(job.viewport_width || 1440));
    const viewportHeight = Math.max(320, Number(job.viewport_height || 1200));
    frame.style.width = `${width}px`;
    frame.style.height = `${viewportHeight}px`;
    frameWrap.hidden = false;
    activeNode.textContent = `Capturing ${job.job_id}…`;

    const loaded = waitForFrameLoad();
    frame.src = job.target_url;
    await loaded;

    const doc = frame.contentDocument;
    if (!doc || !doc.documentElement) {
      throw new Error('Could not access target iframe document. The target may block same-origin framing.');
    }

    await waitForTargetReadiness(doc);

    const body = doc.body;
    const root = doc.documentElement;
    const maxHeight = Math.max(viewportHeight, Number(config.maxCaptureHeight || 8000));
    const contentHeight = Math.max(
      root.scrollHeight || 0,
      body ? body.scrollHeight || 0 : 0,
      viewportHeight,
    );
    const height = job.full_page ? Math.min(contentHeight, maxHeight) : viewportHeight;
    const backgroundColor = (body && doc.defaultView)
      ? doc.defaultView.getComputedStyle(body).backgroundColor || '#ffffff'
      : '#ffffff';

    const dataUrl = await htmlToImage.toPng(root, {
      width,
      height,
      canvasWidth: width,
      canvasHeight: height,
      pixelRatio: 1,
      backgroundColor,
      style: {
        width: `${width}px`,
        minHeight: `${height}px`,
      },
    });

    const comma = dataUrl.indexOf(',');
    if (comma === -1) {
      throw new Error('Capture did not return a valid data URL.');
    }

    return {
      mime_type: dataUrl.slice(5, dataUrl.indexOf(';')) || 'image/png',
      image_base64: dataUrl.slice(comma + 1),
    };
  };

  const completeJob = async (job, payload) => {
    await apiFetch(config.completeUrl, {
      method: 'POST',
      body: JSON.stringify({ job_id: job.job_id, ...payload }),
    });
  };

  const failJob = async (job, error) => {
    await apiFetch(config.completeUrl, {
      method: 'POST',
      body: JSON.stringify({ job_id: job.job_id, error: error.message || String(error) }),
    });
  };

  const processNextJob = async () => {
    if (running || pauseNode.checked) {
      return;
    }

    const job = jobs.find((item) => item.status === 'pending');
    if (!job) {
      activeNode.textContent = 'No active capture.';
      frameWrap.hidden = true;
      return;
    }

    running = true;
    try {
      setStatus(`Processing ${job.job_id}…`);
      const payload = await captureJob(job);
      await completeJob(job, payload);
      setStatus(`Completed ${job.job_id}.`);
    } catch (error) {
      setStatus(`Failed ${job.job_id}: ${error.message}`);
      try {
        await failJob(job, error);
      } catch (completeError) {
        setStatus(`Failed ${job.job_id}; could not store error: ${completeError.message}`);
      }
    } finally {
      running = false;
      await fetchJobs().catch((error) => setStatus(`Queue refresh failed: ${error.message}`));
      window.setTimeout(processNextJob, 250);
    }
  };

  const tick = async () => {
    if (pauseNode.checked) {
      setStatus('Paused.');
      return;
    }

    try {
      await fetchJobs();
      setStatus('Watching for screenshot jobs…');
      await processNextJob();
    } catch (error) {
      setStatus(`Queue refresh failed: ${error.message}`);
    }
  };

  pauseNode.addEventListener('change', () => {
    window.localStorage.setItem('openmiraScreenshotRunnerPaused', pauseNode.checked ? '1' : '0');
    if (!pauseNode.checked) {
      tick();
    } else {
      setStatus('Paused.');
    }
  });

  refreshNode?.addEventListener('click', () => tick());

  heartbeat();
  tick();
  heartbeatTimer = window.setInterval(heartbeat, 5000);
  pollTimer = window.setInterval(tick, Number(config.pollMs || 1500));

  window.addEventListener('beforeunload', () => {
    window.clearInterval(heartbeatTimer);
    window.clearInterval(pollTimer);
  });
})();
