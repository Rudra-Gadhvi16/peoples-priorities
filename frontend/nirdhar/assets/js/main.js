// API Base URL configuration pointing to FastAPI backend
  const API_BASE = '';

  // State Variables
  let currentLang = 'en';
  let currentMode = 'text';
  let activeCategoryFilter = 'all';
  let activeWardFilter = null;
  let activeLedgerTab = 'active'; 
  let allLedgerItems = [];
  let completedProjectsList = [];
  let isRecording = false;
  let recordingInterval = null;
  let recordingSeconds = 0;
  let uploadedPhotoBase64 = null;
  let currentCompletionRating = 5;
  let connectedPhoneNumber = '+91 99887 76655';

  const CATEGORY_LABEL = {
    education: 'Education',
    infrastructure: 'Infrastructure',
    skilling: 'Skilling',
    utilities: 'Utilities',
    general: 'General'
  };

  const CATEGORY_COLOR = {
    education: 'var(--theme-education)',
    infrastructure: 'var(--theme-infrastructure)',
    skilling: 'var(--theme-skilling)',
    utilities: 'var(--theme-utilities)',
    general: 'var(--theme-general)'
  };

  const PROBLEM_IMAGES = {
    education: 'images/problem_education.png',
    infrastructure: 'images/problem_infrastructure.png',
    skilling: 'images/problem_skilling.png',
    utilities: 'images/problem_utilities.png'
  };

  // Nav scroll helpers
  function navigateTo(targetId, navElement) {
    document.querySelectorAll('.links a, .mobile-nav-item').forEach(el => el.classList.remove('active'));
    if (navElement) navElement.classList.add('active');
    
    const target = document.querySelector(targetId);
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  // Active Nav Scroll spy
  window.addEventListener('scroll', () => {
    const sections = ['#home', '#citizen-services', '#ledger'];
    const scrollPos = window.scrollY + 200;
    
    for (const sectionId of sections) {
      const section = document.querySelector(sectionId);
      if (section && scrollPos >= section.offsetTop && scrollPos < section.offsetTop + section.offsetHeight) {
        document.querySelectorAll('.links a').forEach(a => {
          if (a.getAttribute('href') === sectionId) a.classList.add('active');
          else a.classList.remove('active');
        });
        break;
      }
    }
  });

  // Modal actions
  function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
  }
  document.querySelectorAll('.overlay').forEach(o => {
    o.addEventListener('click', e => { if(e.target === o) closeModal(o.id); });
  });
  document.addEventListener('keydown', e => {
    if(e.key === 'Escape') {
      document.querySelectorAll('.overlay.open').forEach(o => closeModal(o.id));
    }
  });

  // Scroll to search tracker
  function focusTrackingWidget() {
    document.getElementById('citizen-services').scrollIntoView({ behavior: 'smooth' });
    const card = document.getElementById('statusTrackerCard');
    card.style.borderColor = 'var(--accent-gold)';
    card.style.boxShadow = '0 0 25px rgba(245, 158, 11, 0.4)';
    setTimeout(() => {
      card.style.borderColor = '';
      card.style.boxShadow = '';
    }, 1500);
    document.getElementById('statusIdInput').focus();
  }

  // Grievance tracking status check
  async function queryGrievanceStatus() {
    const input = document.getElementById('statusIdInput');
    const box = document.getElementById('statusResultsBox');
    const id = input.value.trim();

    if (!id) {
      alert("Please enter a Grievance Registration ID.");
      return;
    }

    try {
      const res = await fetch(`${API_BASE}/api/submissions/status?id=${id}`);
      if (!res.ok) {
        const err = await res.json();
        alert(err.error || "Grievance ID not found.");
        return;
      }

      const data = await res.json();
      
      document.getElementById('statusResCategory').textContent = `${CATEGORY_LABEL[data.category] || data.category} Grievance`;
      document.getElementById('statusResWard').textContent = data.ward_name || "Unspecified Ward";
      
      const badge = document.getElementById('statusResBadge');
      badge.textContent = data.status;
      badge.className = 'status-badge-val';
      if (data.status === 'Completed & Resolved') {
        badge.classList.add('completed');
      } else if (data.status === 'Escalated to Headquarters') {
        badge.classList.add('escalated');
      } else {
        badge.classList.add('pending');
      }

      document.getElementById('statusResRank').textContent = data.rank !== -1 ? `Rank ${data.rank} of ${data.total_ledger_items}` : 'N/A';
      document.getElementById('statusResScore').textContent = `${data.demand_score} / 100`;
      
      const summaryText = data.text;
      document.getElementById('statusResSummary').textContent = summaryText.length > 30 ? summaryText.substring(0, 30) + '...' : summaryText;
      document.getElementById('statusResSummary').title = summaryText;

      const imgRow = document.getElementById('statusResImageRow');
      const imgEl = document.getElementById('statusResImage');
      if (data.image_path) {
        imgEl.src = data.image_path;
        imgRow.style.display = 'flex';
      } else {
        imgRow.style.display = 'none';
      }

      const compDetails = document.getElementById('statusResCompletedDetails');
      if (data.resolution) {
        compDetails.style.display = 'block';
        document.getElementById('statusResActualCost').textContent = `₹${data.resolution.actual_cost_lakhs} Lakhs`;
        document.getElementById('statusResRating').textContent = '★'.repeat(data.resolution.satisfaction_rating) + '☆'.repeat(5 - data.resolution.satisfaction_rating);
        document.getElementById('statusResReviewNotes').textContent = `"${data.resolution.review_text}"`;
      } else {
        compDetails.style.display = 'none';
      }

      box.style.display = 'block';
    } catch (err) {
      console.error(err);
      alert("Error contacting database.");
    }
  }

  // Submit Suggestion
  async function submitGrievance() {
    const textInput = document.getElementById('submitText');
    const wardSelect = document.getElementById('wardSelect');
    const btn = document.getElementById('btnSubmitGrievance');
    const text = textInput.value.trim();
    const ward_id = wardSelect.value;

    if (!text && currentMode === 'text') {
      alert("Please enter details of your grievance.");
      return;
    }
    if (!ward_id) {
      alert("Please select a ward location.");
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Submitting grievance...';

    try {
      const payload = {
        text: text,
        channel: currentMode,
        language: currentLang,
        ward_id: ward_id,
        image: (currentMode === 'photo' && uploadedPhotoBase64) ? uploadedPhotoBase64 : null,
        image_name: (currentMode === 'photo' && uploadedPhotoBase64 && document.getElementById('photoFileInput').files[0]) ? document.getElementById('photoFileInput').files[0].name : null
      };

      const res = await fetch(`${API_BASE}/api/submissions`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      if (!res.ok) throw new Error('API submission error');
      const data = await res.json();
      
      // Update UI with ID instead of alert
      document.getElementById('submitFootNote').innerHTML = `<span style="color:var(--accent-sage)">✅ Success! Grievance ID: <strong style="user-select:all">${data.id}</strong></span>`;
      
      // Copy to input for convenience
      document.getElementById('statusIdInput').value = data.id;
      
      setTimeout(() => {
        closeModal('submitModal');
        textInput.value = '';
        removePhoto();
        document.getElementById('submitFootNote').textContent = 'Grievances filed are assigned a unique tracking number instantly.';
        
        loadDashboard();
        focusTrackingWidget();
        queryGrievanceStatus();
        
        addNotification('📝 New Grievance Registered', `Grievance for ${CATEGORY_LABEL[data.category] || data.category} submitted in Ward ${data.ward_id ? data.ward_id.split('-')[1] : 'Unknown'}.`);
      }, 3500);
    } catch(err) {
      console.error(err);
      alert("Submission failed. Database connection error.");
    } finally {
      btn.disabled = false;
      btn.textContent = 'Submit Grievance Suggestion';
    }
  }

  // Language selectors
  document.querySelectorAll('.lang-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.lang-opt').forEach(b => b.classList.remove('active'));
      const moreSelect = document.getElementById('moreLangSelect');
      if(moreSelect) moreSelect.value = "";
      btn.classList.add('active');
      currentLang = btn.dataset.lang || 'en';
    });
  });

  // Modal Mode Switch tabs (Text, Voice, Photo)
  function setMode(el, mode) {
    currentMode = mode;
    document.querySelectorAll('.mode-tab').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    ['text', 'voice', 'photo'].forEach(m => {
      document.getElementById('mode-' + m).style.display = (m === mode) ? 'block' : 'none';
    });
    
    const footNote = document.getElementById('submitFootNote');
    footNote.style.color = '';
    footNote.textContent = 'Grievances filed are assigned a unique tracking number instantly.';
  }

  // Geotag ward locator
  function useCurrentLocation() {
    const selector = document.getElementById('wardSelect');
    const wards = ['ward-1', 'ward-2', 'ward-4-7', 'ward-9', 'ward-11'];
    const randomWard = wards[Math.floor(Math.random() * wards.length)];
    selector.value = randomWard;
    
    const footNote = document.getElementById('submitFootNote');
    footNote.style.color = 'var(--accent-sage)';
    footNote.textContent = `📍 Geotag resolved: ${selector.options[selector.selectedIndex].text}`;
  }

  let mediaRecorder = null;
  let audioChunks = [];

  async function toggleRecording() {
    const btn = document.getElementById('voiceRecBtn');
    const status = document.getElementById('voiceStatus');
    const timer = document.getElementById('voiceTimer');
    const help = document.getElementById('voiceHelpText');
    const textInput = document.getElementById('submitText');
    const wardSelect = document.getElementById('wardSelect');

    if (!isRecording) {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];

        mediaRecorder.ondataavailable = e => {
          if (e.data.size > 0) audioChunks.push(e.data);
        };

        mediaRecorder.onstop = async () => {
          status.textContent = 'Transcribing Audio...';
          help.textContent = 'Niru STT algorithm translating audio note...';
          
          const audioBlob = new Blob(audioChunks);
          const reader = new FileReader();
          reader.readAsDataURL(audioBlob);
          reader.onloadend = async function() {
            const base64Audio = reader.result;
            
            try {
              const res = await fetch(`${API_BASE}/api/analyze`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  mode: 'audio',
                  media_base64: base64Audio,
                  mime_type: audioBlob.type || 'audio/webm'
                })
              });
              
              if (!res.ok) throw new Error('Analyze API failed');
              const data = await res.json();
              
              status.textContent = 'Transcription Completed';
              help.textContent = 'Audio translated & classified successfully.';
              timer.style.display = 'none';

              const textTab = document.querySelectorAll('.mode-tab')[0];
              setMode(textTab, 'text');
              
              textInput.value = data.analysis.transcription;
              if (data.analysis.ward_id !== 'unknown') {
                // Ensure the ward option exists
                const wardOpt = Array.from(wardSelect.options).find(o => o.value === data.analysis.ward_id);
                if (wardOpt) wardSelect.value = data.analysis.ward_id;
              }
              
              const catLabel = CATEGORY_LABEL[data.analysis.sector.toLowerCase()] || data.analysis.sector;
              const footNote = document.getElementById('submitFootNote');
              footNote.style.color = 'var(--accent-gold)';
              footNote.textContent = `💡 Voice note translated. Detected category: ${catLabel}`;
              
            } catch (err) {
              console.error("Audio Analysis Error:", err);
              status.textContent = 'Error during transcription';
              help.textContent = 'Failed to process audio. Try again.';
            }
          };
        };

        isRecording = true;
        btn.classList.add('recording');
        btn.textContent = '■';
        status.textContent = 'Recording Voice Input...';
        timer.style.display = 'block';
        recordingSeconds = 0;
        timer.textContent = '00:00';
        
        recordingInterval = setInterval(() => {
          recordingSeconds++;
          const minutes = Math.floor(recordingSeconds / 60).toString().padStart(2, '0');
          const seconds = (recordingSeconds % 60).toString().padStart(2, '0');
          timer.textContent = `${minutes}:${seconds}`;
        }, 1000);

        mediaRecorder.start();
      } catch (err) {
        console.error("Microphone access denied:", err);
        alert("Please allow microphone access to record voice notes.");
      }
    } else {
      isRecording = false;
      btn.classList.remove('recording');
      btn.textContent = '●';
      clearInterval(recordingInterval);
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(track => track.stop());
      }
    }
  }

  // Real Photo Upload Analysis
  function handlePhotoUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = async function(e) {
      uploadedPhotoBase64 = e.target.result;
      document.getElementById('photoThumbnail').src = e.target.result;
      document.getElementById('photoName').textContent = file.name;
      document.getElementById('photoSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
      
      document.getElementById('photoPreviewContainer').style.display = 'flex';
      document.getElementById('aiAnalysisBanner').style.display = 'flex';
      
      try {
        const res = await fetch(`${API_BASE}/api/analyze`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            mode: 'image',
            media_base64: uploadedPhotoBase64,
            mime_type: file.type || 'image/jpeg'
          })
        });
        
        if (!res.ok) throw new Error('Analyze API failed');
        const data = await res.json();
        
        document.getElementById('aiAnalysisBanner').style.border = '1px solid rgba(16, 185, 129, 0.25)';
        document.getElementById('aiAnalysisBanner').style.background = 'rgba(16, 185, 129, 0.08)';
        document.getElementById('aiAnalysisBanner').style.color = 'var(--accent-sage)';
        document.getElementById('aiAnalysisBanner').querySelector('.spinner').style.display = 'none';
        
        const catLabel = CATEGORY_LABEL[data.analysis.sector.toLowerCase()] || data.analysis.sector;
        document.getElementById('aiAnalysisText').innerHTML = `<strong>Niru-Vision AI:</strong> ${data.analysis.transcription} (Theme: ${catLabel})`;
        document.getElementById('submitText').value = `[AI Vision Report]: ${data.analysis.transcription}`;
        
        const wardSelect = document.getElementById('wardSelect');
        if (data.analysis.ward_id !== 'unknown') {
          const wardOpt = Array.from(wardSelect.options).find(o => o.value === data.analysis.ward_id);
          if (wardOpt) wardSelect.value = data.analysis.ward_id;
        }
        
        const footNote = document.getElementById('submitFootNote');
        footNote.style.color = 'var(--accent-sage)';
        footNote.textContent = `💡 Photo analysis matching theme: ${catLabel}.`;
      } catch (err) {
        console.error("Image Analysis Error:", err);
        document.getElementById('aiAnalysisText').innerHTML = `<strong>Error:</strong> Failed to analyze image.`;
        document.getElementById('aiAnalysisBanner').querySelector('.spinner').style.display = 'none';
      }
    };
    reader.readAsDataURL(file);
  }

  function removePhoto() {
    uploadedPhotoBase64 = null;
    document.getElementById('photoFileInput').value = '';
    document.getElementById('photoPreviewContainer').style.display = 'none';
    document.getElementById('aiAnalysisBanner').style.display = 'none';
    document.getElementById('aiAnalysisBanner').style.border = '';
    document.getElementById('aiAnalysisBanner').style.background = '';
    document.getElementById('aiAnalysisBanner').style.color = '';
    document.getElementById('aiAnalysisBanner').querySelector('.spinner').style.display = 'block';
    document.getElementById('aiAnalysisText').textContent = 'Analyzing image with Niru-Vision AI...';
    document.getElementById('submitText').value = '';
  }

  // Helper context chips
  function contextChips(item) {
    const c = item.context || {};
    const chips = [`${item.mentions} mentions`];
    if(c.enrollment_vs_capacity_pct !== undefined) chips.push(`+${c.enrollment_vs_capacity_pct}% capacity gap`);
    if(c.avg_travel_distance_km !== undefined) chips.push(`${c.avg_travel_distance_km} km travel`);
    if(c.recurring_seasons !== undefined) chips.push(`${c.recurring_seasons} seasons flood`);
    if(c.nearest_centre_km !== undefined) chips.push(`${c.nearest_centre_km} km to centre`);
    if(c.hamlet_clusters_unserved !== undefined) chips.push(`${c.hamlet_clusters_unserved} cluster unserved`);
    if(c.plan_ref) chips.push(`Ref: ${c.plan_ref}`);
    return chips;
  }

  // Draw Pulsing Map Hotspots
  function renderMapHotspots(hotspots) {
    const g = document.getElementById('mapHotspots');
    if (!g) return;
    g.innerHTML = '';
    
    hotspots.forEach(h => {
      const color = CATEGORY_COLOR[h.top_category] || 'var(--theme-general)';
      const r = Math.min(Math.max(h.total_mentions / 5, 8), 26);
      const pulseR = r + 12;
      
      const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      group.setAttribute('class', `hotspot-group ${activeWardFilter === h.ward_id ? 'active' : ''}`);
      group.setAttribute('data-ward', h.ward_id);
      group.style.cursor = 'pointer';
      
      const pulseRing = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      pulseRing.setAttribute('cx', h.lat);
      pulseRing.setAttribute('cy', h.lng);
      pulseRing.setAttribute('r', pulseR);
      pulseRing.setAttribute('fill', color);
      pulseRing.setAttribute('opacity', '0.18');
      pulseRing.setAttribute('class', 'hotspot-pulse');
      
      const dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      dot.setAttribute('cx', h.lat);
      dot.setAttribute('cy', h.lng);
      dot.setAttribute('r', r);
      dot.setAttribute('fill', color);
      dot.setAttribute('opacity', '0.85');
      dot.setAttribute('class', 'hotspot-solid');
      
      const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
      title.textContent = `${h.name || h.ward_id}: ${h.total_mentions} mentions (Top: ${CATEGORY_LABEL[h.top_category]})`;
      dot.appendChild(title);
      
      group.appendChild(pulseRing);
      group.appendChild(dot);
      
      group.addEventListener('click', () => {
        filterByWard(h.ward_id, h.name || h.ward_id);
      });
      
      g.appendChild(group);
    });
  }

  function filterByCategory(cat) {
    switchLedgerTab('active');
    const tabs = document.querySelectorAll('.filter-tab');
    tabs.forEach(t => {
      const isMatch = t.getAttribute('onclick').includes(`'${cat}'`);
      if (isMatch) setFilterTab(t, cat);
    });
    document.getElementById('ledger').scrollIntoView({ behavior: 'smooth' });
  }

  function filterByWard(wardId, wardName) {
    switchLedgerTab('active');
    activeWardFilter = wardId;
    
    document.querySelectorAll('.hotspot-group').forEach(group => {
      if(group.getAttribute('data-ward') === wardId) group.classList.add('active');
      else group.classList.remove('active');
    });

    const badge = document.getElementById('hotspotFilterBadge');
    const badgeName = document.getElementById('hotspotFilterName');
    badge.style.display = 'flex';
    badgeName.textContent = wardName;

    filterLedger();
    document.getElementById('ledger').scrollIntoView({ behavior: 'smooth' });
  }

  function clearHotspotFilter() {
    activeWardFilter = null;
    document.querySelectorAll('.hotspot-group').forEach(group => group.classList.remove('active'));
    document.getElementById('hotspotFilterBadge').style.display = 'none';
    filterLedger();
  }

  function setFilterTab(el, cat) {
    document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    activeCategoryFilter = cat;
    filterLedger();
  }

  function switchLedgerTab(tabType) {
    activeLedgerTab = tabType;
    const activeBtn = document.getElementById('btnActiveTab');
    const completedBtn = document.getElementById('btnCompletedTab');
    const activeWrapper = document.getElementById('activeLedgerWrapper');
    const completedWrapper = document.getElementById('completedLedgerWrapper');
    
    if (tabType === 'active') {
      activeBtn.classList.add('active');
      completedBtn.classList.remove('active');
      activeWrapper.style.display = 'block';
      completedWrapper.style.display = 'none';
      filterLedger();
    } else {
      activeBtn.classList.remove('active');
      completedBtn.classList.add('active');
      activeWrapper.style.display = 'none';
      completedWrapper.style.display = 'block';
      loadCompletedProjects();
    }
  }

  // Filter Active Ledger Items list
  function filterLedger() {
    const searchVal = document.getElementById('ledgerSearch').value.toLowerCase().trim();
    const listElement = document.getElementById('ledgerList');
    
    let filtered = allLedgerItems;

    if (activeCategoryFilter !== 'all') {
      filtered = filtered.filter(item => item.category === activeCategoryFilter);
    }
    if (activeWardFilter) {
      filtered = filtered.filter(item => item.ward_id === activeWardFilter);
    }

    if (searchVal) {
      filtered = filtered.filter(item => {
        const desc = CATEGORY_LABEL[item.category] || item.category;
        return item.ward_name.toLowerCase().includes(searchVal) ||
               desc.toLowerCase().includes(searchVal) ||
               item.category.toLowerCase().includes(searchVal) ||
               (item.context && item.context.plan_ref && item.context.plan_ref.toLowerCase().includes(searchVal));
      });
    }

    if (filtered.length === 0) {
      listElement.innerHTML = `
        <div class="no-results">
          <span class="icon">🔍</span>
          <strong>No active priorities found</strong>
          <p style="margin: 8px 0 0; font-size: 13px; opacity: 0.75;">Adjust keywords filters or check completed archive.</p>
        </div>
      `;
      return;
    }

    const maxScore = Math.max(...allLedgerItems.map(i => i.demand_score), 1);
    
    listElement.innerHTML = filtered.map(item => {
      const isSlaBreach = item.age_days > 5;
      const isEscalated = item.escalated;
      const slaClass = isSlaBreach ? (isEscalated ? 'sla-breach escalated' : 'sla-breach') : '';
      
      const citizenPhones = {
        "ward-4-7": "+91 98334 11223",
        "ward-9": "+91 90045 88776",
        "ward-2": "+91 88796 55443",
        "ward-11": "+91 91673 22334",
        "ward-1": "+91 99201 44556"
      };
      const phoneNum = citizenPhones[item.ward_id] || "+91 98765 43210";

      return `
        <div class="clause cat-${item.category} ${item.rank === 1 ? 'rank-1' : ''} ${slaClass}">
          <div class="clause-top">
            <div>
              <div class="clause-num" style="display:flex; align-items:center; gap:8px;">
                <span>${item.rank_label}</span>
                ${isEscalated ? 
                  `<span class="sla-badge" style="background:rgba(59,130,246,0.12); border-color:rgba(59,130,246,0.3); color:var(--accent-blue); animation:none;">🚨 Escalated to HQ</span>` : 
                  (isSlaBreach ? `<span class="sla-badge">⚠️ SLA Breach: ${item.age_days} Days Unresolved</span>` : '')
                }
              </div>
              <div class="clause-title">${CATEGORY_LABEL[item.category] || item.category} priority — ${item.ward_name}</div>
            </div>
            <div class="clause-cat">${CATEGORY_LABEL[item.category] || item.category}</div>
          </div>
          ${PROBLEM_IMAGES[item.category] ? `<img src="${PROBLEM_IMAGES[item.category]}" class="clause-img" alt="${CATEGORY_LABEL[item.category]} problem" loading="lazy">` : ''}
          <div class="clause-desc">Priority Score ${item.demand_score}/100 — weighted citizen mentions (55%), capacity gap data (30%), beneficiaries target (15%).</div>
          <div class="demand-bar-track">
            <div class="demand-bar-fill" style="width: ${Math.round(item.demand_score / maxScore * 100)}%"></div>
          </div>
          <div class="chips">
            ${contextChips(item).map(c => `<span class="chip">${c}</span>`).join('')}
            <a href="tel:${phoneNum.replace(/\s+/g, '')}" class="chip" style="border-color:rgba(59,130,246,0.45); color:var(--accent-blue); display:inline-flex; align-items:center; gap:4px; font-weight:500; background:rgba(59,130,246,0.02)">
              📞 Call Citizen (${phoneNum})
            </a>
            
            ${isSlaBreach && !isEscalated ? 
              `<button class="btn-primary" onclick="openEscalateModal('${item.category}', '${item.ward_id}', '${item.ward_name}', ${item.age_days})" style="padding: 6px 12px; font-size: 11px; font-weight: 600; border-radius: var(--radius-sm); margin-left: 8px; background: rgba(244, 63, 94, 0.15); border: 1px solid rgba(244, 63, 94, 0.3); color: var(--accent-vermil); box-shadow: none; min-height: auto; cursor:pointer;">🚨 Escalate</button>` : 
              ''
            }
            
            <button class="btn-primary" onclick="openCompleteProjectModal('${item.category}', '${item.ward_id}', '${item.ward_name}', ${item.context.beneficiaries || 0})" style="padding: 6px 12px; font-size: 11px; font-weight: 600; border-radius: var(--radius-sm); margin-left: auto; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--accent-sage); box-shadow: none; min-height: auto; cursor:pointer;">✓ Resolve</button>
          </div>
        </div>
      `;
    }).join('');
  }

  // Comparison utilities
  function populateCompareDropdowns() {
    const selectA = document.getElementById('compareA');
    const selectB = document.getElementById('compareB');
    if (!selectA || !selectB) return;

    const options = [
      { value: 'education:ward-4-7', label: 'Education — Ward 4-7 (School capacity)' },
      { value: 'infrastructure:ward-9', label: 'Infrastructure — Ward 9 (Drainage)' },
      { value: 'skilling:ward-2', label: 'Skilling — Ward 2 (Vocational ITI)' },
      { value: 'utilities:ward-11', label: 'Utilities — Ward 11 (Water supply)' },
      { value: 'utilities:ward-1', label: 'Utilities — Ward 1 (Streetlights)' }
    ];

    let activeOptions = options;
    if (allLedgerItems && allLedgerItems.length > 0) {
      activeOptions = options.filter(opt => {
        const [cat, ward] = opt.value.split(':');
        return allLedgerItems.some(item => item.category === cat && item.ward_id === ward);
      });
    }
    if (activeOptions.length < 2) activeOptions = options;

    selectA.innerHTML = activeOptions.map(opt => `<option value="${opt.value}">${opt.label}</option>`).join('');
    selectB.innerHTML = activeOptions.map(opt => `<option value="${opt.value}">${opt.label}</option>`).join('');

    if (activeOptions.length >= 2) {
      selectA.value = activeOptions[0].value;
      selectB.value = activeOptions[1].value;
    }
    runComparison();
  }

  async function runComparison() {
    const selectA = document.getElementById('compareA').value;
    const selectB = document.getElementById('compareB').value;
    const loader = document.getElementById('compareLoader');
    
    loader.classList.add('active');
    try {
      const res = await fetch(`${API_BASE}/api/compare?a=${selectA}&b=${selectB}`);
      if (!res.ok) throw new Error('API comparison error');
      const data = await res.json();
      renderCompareCard('comp', data.a, data.recommended === 'a');
      renderCompareCard('comp', data.b, data.recommended === 'b', true);
    } catch(err) {
      fallbackCompare(selectA, selectB);
    } finally {
      setTimeout(() => loader.classList.remove('active'), 300);
    }
  }

  function renderCompareCard(prefix, item, isWinner, isCardB = false) {
    const cardEl = document.getElementById(isCardB ? 'compCardB' : 'compCardA');
    const nameEl = document.getElementById(isCardB ? 'compNameB' : 'compNameA');
    const scoreEl = document.getElementById(isCardB ? 'compScoreB' : 'compScoreA');
    const mentionsEl = document.getElementById(isCardB ? 'compMentionsB' : 'compMentionsA');
    const gapEl = document.getElementById(isCardB ? 'compGapB' : 'compGapA');
    const benefEl = document.getElementById(isCardB ? 'compBenefB' : 'compBenefA');
    const tagEl = document.getElementById(isCardB ? 'compTagB' : 'compTagA');

    nameEl.textContent = `${CATEGORY_LABEL[item.category] || item.category} Priority — ${item.ward_name}`;
    scoreEl.textContent = `${item.demand_score} / 100`;
    mentionsEl.textContent = item.mentions;

    const ctx = item.context || {};
    let gapMetric = '--';
    if (ctx.enrollment_vs_capacity_pct !== undefined) gapMetric = `+${ctx.enrollment_vs_capacity_pct}% capacity gap`;
    else if (ctx.nearest_centre_km !== undefined) gapMetric = `${ctx.nearest_centre_km} km to centre`;
    else if (ctx.recurring_seasons !== undefined) gapMetric = `${ctx.recurring_seasons} seasons flood`;
    else if (ctx.hamlet_clusters_unserved !== undefined) gapMetric = `${ctx.hamlet_clusters_unserved} cluster unserved`;
    
    gapEl.textContent = gapMetric;
    benefEl.textContent = ctx.beneficiaries ? ctx.beneficiaries.toLocaleString() : '--';

    if (isWinner) {
      cardEl.className = 'compare-item win';
      tagEl.className = 'win-tag';
      tagEl.textContent = '🏆 High Priority Recommendation';
    } else {
      cardEl.className = 'compare-item lose';
      tagEl.className = 'lose-tag';
      tagEl.textContent = 'Recommended Phase 2';
    }
  }

  function fallbackCompare(specA, specB) {
    const getMockData = (spec) => {
      const [category, wardId] = spec.split(':');
      const item = allLedgerItems.find(i => i.category === category && i.ward_id === wardId);
      if (item) return item;
      return { category, ward_id: wardId, ward_name: wardId.toUpperCase(), mentions: 5, demand_score: 10.0, context: {} };
    };
    const itemA = getMockData(specA);
    const itemB = getMockData(specB);
    const winner = itemA.demand_score >= itemB.demand_score ? 'a' : 'b';
    renderCompareCard('comp', itemA, winner === 'a');
    renderCompareCard('comp', itemB, winner === 'b', true);
  }

  // Resolve Grievance Complete
  function openCompleteProjectModal(category, wardId, wardName, beneficiaries) {
    document.getElementById('completeProjectCategory').value = category;
    document.getElementById('completeProjectWard').value = wardId;
    document.getElementById('completeProjectLabel').textContent = `${CATEGORY_LABEL[category]} project in ${wardName}`;
    
    let mockCost = "15.0";
    if (category === 'education') mockCost = "45.0";
    else if (category === 'infrastructure') mockCost = "65.0";
    else if (category === 'skilling') mockCost = "25.0";
    else if (category === 'utilities') mockCost = "35.0";
    
    document.getElementById('completeProjectCost').value = mockCost;
    document.getElementById('completeProjectNotes').value = '';
    
    setCompletionStarRating(5);
    openModal('completeProjectModal');
  }

  function setCompletionStarRating(rating) {
    currentCompletionRating = rating;
    document.getElementById('completeProjectRating').value = rating;
    const stars = document.querySelectorAll('#completeProjectStars .star-btn');
    stars.forEach((star, index) => {
      if (index < rating) star.classList.add('active');
      else star.classList.remove('active');
    });
  }

  async function saveProjectCompletion() {
    const category = document.getElementById('completeProjectCategory').value;
    const wardId = document.getElementById('completeProjectWard').value;
    const cost = document.getElementById('completeProjectCost').value;
    const rating = document.getElementById('completeProjectRating').value;
    const notes = document.getElementById('completeProjectNotes').value.trim();
    const btn = document.getElementById('btnSaveCompletion');

    if (!cost || parseFloat(cost) <= 0) {
      alert("Please enter a valid cost in Lakhs.");
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Saving redressal...';

    try {
      const res = await fetch(`${API_BASE}/api/projects/complete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          category,
          ward_id: wardId,
          actual_cost: parseFloat(cost),
          satisfaction_rating: parseInt(rating),
          review_text: notes || `Grievance resolved successfully under ${CATEGORY_LABEL[category]} category.`
        })
      });

      if (!res.ok) throw new Error('API complete error');
      
      addNotification(
        '✓ Project Resolved & Archived', 
        `Completed ${CATEGORY_LABEL[category]} upgrades in Ward ${wardId.split('-')[1]}. Spent: ₹${parseFloat(cost)}L`
      );

      closeModal('completeProjectModal');
      loadDashboard();
    } catch(err) {
      console.error(err);
      alert("Database error saving completion details.");
    } finally {
      btn.disabled = false;
      btn.textContent = '✓ Save & Resolve';
    }
  }

  // Load Completed Projects list
  async function loadCompletedProjects() {
    const listElement = document.getElementById('completedProjectsList');
    try {
      const res = await fetch(`${API_BASE}/api/projects/completed`);
      if (!res.ok) throw new Error('API completed list error');
      completedProjectsList = await res.json();
    } catch(err) {
      console.error(err);
    }

    if (completedProjectsList.length === 0) {
      listElement.innerHTML = `
        <div class="no-results" style="margin-top:12px;">
          <span class="icon">📜</span>
          <strong>No resolved grievances archived yet</strong>
          <p style="margin: 8px 0 0; font-size: 13px; opacity: 0.75;">Click "Resolve" on priority ledger items to complete them.</p>
        </div>
      `;
      return;
    }

    listElement.innerHTML = completedProjectsList.map(item => {
      const stars = '★'.repeat(item.satisfaction_rating) + '☆'.repeat(5 - item.satisfaction_rating);
      const dateString = new Date(item.completed_at * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
      return `
        <div class="clause cat-${item.category}" style="border-color: rgba(16, 185, 129, 0.25); background: rgba(16, 185, 129, 0.015);">
          <div class="clause-top">
            <div>
              <div class="clause-num" style="color: var(--accent-sage); font-weight:600;">GRIEVANCE RESOLVED &amp; ARCHIVED</div>
              <div class="clause-title" style="color: var(--text-primary);">${CATEGORY_LABEL[item.category] || item.category} updates — ${item.ward_name}</div>
            </div>
            <div class="clause-cat" style="color:var(--accent-sage); border-color:rgba(16, 185, 129, 0.35); background:rgba(16,185,129,0.05);">${CATEGORY_LABEL[item.category] || item.category}</div>
          </div>
          <div style="display:flex; align-items:center; gap:10px; margin:10px 0;">
            <div style="font-size: 18px; color: var(--accent-gold); line-height: 1; letter-spacing:1px;">${stars}</div>
            <div style="font-size:11px; color:var(--text-muted); font-family:'IBM Plex Mono', monospace;">Satisfaction Rating</div>
          </div>
          <div class="clause-desc" style="color: var(--text-secondary); border-left:2px solid rgba(255,255,255,0.05); padding-left:12px; font-style:italic;">"${item.review_text}"</div>
          <div class="chips">
            <span class="chip" style="border-color: rgba(16, 185, 129, 0.25); color: var(--accent-sage); background: rgba(16,185,129,0.02)">Cost: ₹${item.actual_cost_lakhs} Lakhs</span>
            <span class="chip">Resolved Date: ${dateString}</span>
          </div>
        </div>
      `;
    }).join('');
  }

  // Load stats and ledger items
  async function loadDashboard() {
    try {
      const [statsRes, ledgerRes, hotspotsRes] = await Promise.all([
        fetch(`${API_BASE}/api/stats`),
        fetch(`${API_BASE}/api/ledger`),
        fetch(`${API_BASE}/api/hotspots`)
      ]);
      
      let stats = {};
      if (statsRes.ok) {
        stats = await statsRes.json();
        
        document.getElementById('statTotal').textContent = stats.total_submissions.toLocaleString();
        
        const budgetPct = Math.min((stats.utilized_budget_lakhs / stats.total_budget_lakhs) * 100, 100);
        document.getElementById('profileBudgetPct').textContent = `${budgetPct.toFixed(0)}%`;
        document.getElementById('profileBudgetBar').style.width = `${budgetPct}%`;
        document.getElementById('profileSpentBudget').textContent = `₹${stats.utilized_budget_lakhs}L`;
        document.getElementById('profileBalanceBudget').textContent = `₹${stats.remaining_budget_lakhs}L`;
        document.getElementById('profileCompletedCount').textContent = stats.completed_count;
        document.getElementById('profileAvgRating').textContent = stats.average_satisfaction;
      }
      
      if (ledgerRes.ok) {
        allLedgerItems = await ledgerRes.json();
        filterLedger();
        document.getElementById('profileActiveCount').textContent = allLedgerItems.length;
        
        const topSchool = allLedgerItems.find(i => i.category === 'education' && i.ward_id === 'ward-4-7');
        if (topSchool) document.getElementById('statHighlightsSchool').textContent = topSchool.mentions;
        
        populateCompareDropdowns();
      }

      if (hotspotsRes.ok) {
        const hotspots = await hotspotsRes.json();
        renderMapHotspots(hotspots);
        document.getElementById('statHotspotNote').textContent = `across ${hotspots.length} active wards`;
      }
      
      // Load dynamic citizen photos
      loadCitizenPhotos();
    } catch (err) {
      console.warn("API load error. Sandboxed local execution.", err);
    }
  }

  // Fetch and render citizen photo submissions
  async function loadCitizenPhotos() {
    const strip = document.getElementById('citizenPhotoStrip');
    const section = document.getElementById('citizen-photo-feed-section');
    if (!strip || !section) return;

    try {
      const res = await fetch(`${API_BASE}/api/submissions?has_image=true`);
      if (!res.ok) throw new Error('Failed to load photos');
      const submissions = await res.json();

      if (submissions.length === 0) {
        section.style.display = 'none';
        return;
      }

      section.style.display = 'block';
      strip.innerHTML = submissions.slice(0, 6).map(sub => {
        const dateStr = new Date(sub.created_at * 1000).toLocaleDateString('en-IN', {
          day: 'numeric', month: 'short', year: 'numeric'
        });
        const WARD_NAMES = {
          "ward-1": "Ward 1",
          "ward-2": "Ward 2",
          "ward-4-7": "Ward 4-7",
          "ward-9": "Ward 9",
          "ward-11": "Ward 11"
        };
        const wardLabel = sub.ward_id ? (WARD_NAMES[sub.ward_id] || sub.ward_id) : 'General';
        
        return `
          <div class="ground-card" style="cursor:pointer;" onclick="viewSubmissionStatus('${sub.id}')">
            <img src="${sub.image_path}" alt="${CATEGORY_LABEL[sub.category] || sub.category}" loading="lazy">
            <div class="ground-label">
              <span class="ground-tag" style="background:var(--accent-gold-glow); border-color:var(--card-border-hover); color:var(--accent-gold);">
                ${CATEGORY_LABEL[sub.category] || sub.category}
              </span>
              <div class="ground-caption">${sub.text.length > 60 ? sub.text.substring(0, 60) + '...' : sub.text}</div>
              <div class="ground-sub">${wardLabel} · Citizen Submission · ${dateStr}</div>
            </div>
          </div>
        `;
      }).join('');
    } catch (err) {
      console.error('Error loading citizen photos:', err);
    }
  }

  // Quick helper to view submission status from gallery click
  function viewSubmissionStatus(id) {
    document.getElementById('statusIdInput').value = id;
    focusTrackingWidget();
    queryGrievanceStatus();
  }

  // HQ Escalation modal
  function openEscalateModal(category, wardId, wardName, ageDays) {
    document.getElementById('escalateProjectCategory').value = category;
    document.getElementById('escalateProjectWard').value = wardId;
    document.getElementById('escalateProjectLabel').textContent = `${CATEGORY_LABEL[category]} demand in ${wardName}`;
    document.getElementById('escalateProjectAge').textContent = `🚨 Pending for ${ageDays} days (SLA Target Limit: 5 Days)`;
    
    const textMsg = `URGENT ESCALATION - SLA BREACH WARNING\n\n` +
      `Category: ${CATEGORY_LABEL[category]}\n` +
      `Location: ${wardName}\n` +
      `Status: SLA Breach Warning (${ageDays} Days Unresolved)\n` +
      `Required: Emergency intervention and local budget disbursement.`;
      
    document.getElementById('escalateProjectMessage').value = textMsg;
    
    updateEscalationWhatsAppLink();
    openModal('escalateProjectModal');
  }

  function updateEscalationWhatsAppLink() {
    const selectPhone = document.getElementById('hqContactSelect').value;
    const msg = document.getElementById('escalateProjectMessage').value;
    const waLink = `https://wa.me/${selectPhone.replace(/[\s+]/g, '')}?text=${encodeURIComponent(msg)}`;
    document.getElementById('btnEscalateWhatsApp').href = waLink;
  }

  async function sendProjectEscalation() {
    const category = document.getElementById('escalateProjectCategory').value;
    const wardId = document.getElementById('escalateProjectWard').value;
    const selectPhone = document.getElementById('hqContactSelect').value;
    const btn = document.getElementById('btnSendEscalation');

    btn.disabled = true;
    btn.textContent = 'Escalating to HQ...';

    try {
      const res = await fetch(`${API_BASE}/api/projects/escalate`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ category, ward_id: wardId })
      });

      if (!res.ok) throw new Error('API escalate error');

      addNotification(
        '🚨 Project Escalated to HQ', 
        `Escalated ${CATEGORY_LABEL[category]} in Ward ${wardId.split('-')[1]} to District Chief Commissioner.`
      );

      closeModal('escalateProjectModal');
      loadDashboard();
    } catch(err) {
      console.error(err);
      alert("Database error executing escalation.");
    } finally {
      btn.disabled = false;
      btn.textContent = '🚨 Send Escalation Alert';
    }
  }

  // Linked MP Phone Saving
  async function saveConnectedPhone() {
    const phoneInput = document.getElementById('mpPhoneNumber');
    const statusMsg = document.getElementById('phoneStatusMsg');
    const phoneVal = phoneInput.value.trim();

    if (!phoneVal) {
      statusMsg.textContent = '⚠️ Please enter a valid phone number.';
      statusMsg.style.color = 'var(--accent-vermil)';
      return;
    }

    try {
      const res = await fetch(`${API_BASE}/api/settings/phone`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone_number: phoneVal })
      });
      if (!res.ok) throw new Error('API phone link error');
      
      connectedPhoneNumber = phoneVal;
      statusMsg.innerHTML = `✅ Linked to <strong>${phoneVal}</strong>. Automated SLA SMS notifications enabled.`;
      statusMsg.style.color = 'var(--accent-sage)';
      
      addNotification('📱 Phone Link Updated', `Representative SMS target number updated to: ${phoneVal}`);
    } catch (err) {
      console.error(err);
      statusMsg.textContent = '⚠️ Backend connection error. Could not link phone.';
      statusMsg.style.color = 'var(--accent-vermil)';
    }
  }

  // Notifications systems
  let notifications = [
    { id: 1, title: '⚠️ SLA Breach: Ward 9 Drainage', body: 'Infrastructure grievance in Ward 9 has been pending for 6.2 days.', time: '2 hours ago', read: false },
    { id: 2, title: '📊 Budget Utilization Updated', body: 'Cost allocation statistics generated for active projects.', time: '1 day ago', read: true }
  ];

  function toggleNotificationDropdown(e) {
    e.stopPropagation();
    document.getElementById('notificationDropdown').classList.toggle('open');
  }

  document.addEventListener('click', () => {
    document.getElementById('notificationDropdown').classList.remove('open');
  });

  function renderNotifications() {
    const list = document.getElementById('notificationList');
    const badge = document.getElementById('notificationBadge');
    const unreadCount = notifications.filter(n => !n.read).length;
    
    badge.textContent = unreadCount;
    badge.style.display = unreadCount > 0 ? 'flex' : 'none';

    if (notifications.length === 0) {
      list.innerHTML = `<div class="notification-empty">No alerts or notifications</div>`;
      return;
    }

    list.innerHTML = notifications.map(n => `
      <div class="notification-item ${n.read ? '' : 'unread'}" onclick="markNotificationRead(${n.id}, event)">
        <div class="content">
          <div class="title">${n.title}</div>
          <div style="font-size:11px; opacity:0.85; line-height:1.35;">${n.body}</div>
          <div class="time">${n.time}</div>
        </div>
      </div>
    `).join('');
  }

  function markNotificationRead(id, e) {
    e.stopPropagation();
    const n = notifications.find(notif => notif.id === id);
    if (n) n.read = true;
    renderNotifications();
  }

  function markAllNotificationsRead(e) {
    e.stopPropagation();
    notifications.forEach(n => n.read = true);
    renderNotifications();
  }

  function addNotification(title, body) {
    notifications.unshift({
      id: Date.now(),
      title: title,
      body: body,
      time: 'Just now',
      read: false
    });
    renderNotifications();
  }

  // Niru AI chatbot responses
  function toggleAIChat() {
    document.getElementById('aiChatPane').classList.toggle('open');
  }

  function handleChatKeyDown(e) {
    if (e.key === 'Enter') sendChatMessage();
  }

  function sendChatMessage() {
    const input = document.getElementById('aiChatInput');
    const text = input.value.trim();
    if (!text) return;

    appendChatMessage('user', text);
    input.value = '';

    setTimeout(() => {
      const response = generateAIResponse(text.toLowerCase());
      appendChatMessage('bot', response);
    }, 700);
  }

  function sendQuickPrompt(type) {
    let promptText = '';
    if (type === 'budget') promptText = "How is my budget utilization looking?";
    else if (type === 'priority') promptText = "What is the highest priority item?";
    else if (type === 'sla') promptText = "Which projects are in SLA breach?";
    else if (type === 'draft') promptText = "Draft an escalation email for Ward 9 drainage";
    
    if (promptText) {
      appendChatMessage('user', promptText);
      setTimeout(() => {
        const response = generateAIResponse(type);
        appendChatMessage('bot', response);
      }, 600);
    }
  }

  function appendChatMessage(sender, text) {
    const messages = document.getElementById('aiChatMessages');
    const msgDiv = document.createElement('div');
    msgDiv.className = `chat-msg ${sender}`;
    if (text.includes('\n')) msgDiv.innerHTML = text.replace(/\n/g, '<br>');
    else msgDiv.innerHTML = text;
    messages.appendChild(msgDiv);
    messages.scrollTop = messages.scrollHeight;
  }

  function generateAIResponse(input) {
    if (input.includes('budget') || input.includes('spent') || input.includes('crore')) {
      const spent = document.getElementById('profileSpentBudget')?.textContent || "₹0.0L";
      const remaining = document.getElementById('profileBalanceBudget')?.textContent || "₹500.0L";
      const pct = document.getElementById('profileBudgetPct')?.textContent || "0%";
      const completed = document.getElementById('profileCompletedCount')?.textContent || "0";
      
      return `📊 <strong>Nirdhar Budget Redressal Status Summary:</strong><br><br>` +
        `• <strong>Total Budget:</strong> ₹5.00 Crore (₹500.0 Lakhs)<br>` +
        `• <strong>Allocated/Spent:</strong> ${spent} (${pct} utilized)<br>` +
        `• <strong>Remaining Balance:</strong> ${remaining}<br>` +
        `• <strong>Completed & Resolved Grievances:</strong> ${completed} items archived.`;
    }

    if (input.includes('priority') || input.includes('rank') || input.includes('highest')) {
      if (allLedgerItems.length > 0) {
        const top = allLedgerItems[0];
        return `🏆 <strong>Highest Priority Citizen Demand:</strong><br><br>` +
          `• <strong>Grievance:</strong> ${CATEGORY_LABEL[top.category]} upgrades in <strong>${top.ward_name}</strong><br>` +
          `• <strong>Mentions:</strong> ${top.mentions} citizen complaints<br>` +
          `• <strong>Demand Priority Score:</strong> ${top.demand_score}/100<br>` +
          `• <strong>Plan Ref:</strong> ${top.context.plan_ref || "EDU-04"}<br><br>` +
          `This project is heavily demanded based on citizens' school capacity gaps and travel metrics.`;
      }
      return "There are no active priorities in the ledger. All project demands are currently met or completed.";
    }

    if (input.includes('sla') || input.includes('breach') || input.includes('unresolved') || input.includes('red signal')) {
      const breaches = allLedgerItems.filter(item => item.age_days > 5);
      if (breaches.length > 0) {
        let listText = breaches.map(b => `• <strong>${CATEGORY_LABEL[b.category]}</strong> in ${b.ward_name} (${b.age_days} Days Unresolved) - ${b.escalated ? 'Escalated' : 'Needs Action'}`).join('<br>');
        return `🚨 <strong>Active SLA Breaches (Pending over 5 Days):</strong><br><br>${listText}<br><br>Please click the red <strong>"Escalate to HQ"</strong> button on these cards to route them directly to the state chief.`;
      }
      return "✅ Great news! There are currently no active priorities in SLA breach. All items are under the 5-day limit.";
    }

    if (input.includes('draft') || input.includes('email') || input.includes('template') || input.includes('escalate')) {
      return `✉️ <strong>Escalation Email Draft (Ward 9 Drainage):</strong><br><br>` +
        `<pre>Subject: URGENT ESCALATION: Ward 9 Drainage SLA Breach\n\n` +
        `Dear Headquarters Committee,\n\n` +
        `I am escalating the Ward 9 Storm Drainage Rehabilitation priority. This project has breached our 5-day SLA limits and remains unresolved with 6.2 days pending.\n\n` +
        `This issue has 88 direct citizen flood complaints. I request immediate budget release and deployment of municipal field engineers to resolve this monsoon hazard.\n\n` +
        `Best regards,\n` +
        `Rajesh Patil, MP Office</pre>` +
        `<button class="btn-copy" onclick="navigator.clipboard.writeText(this.previousElementSibling.innerText); this.textContent='Copied!';">Copy to Clipboard</button>`;
    }

    return "I can help you monitor constituency demands. You can type keywords like: <strong>budget</strong>, <strong>priority</strong>, <strong>sla</strong>, or <strong>draft</strong> to get immediate reports.";
  }

  // Initialize
  renderNotifications();
  loadDashboard();