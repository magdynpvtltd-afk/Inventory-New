<?php
// MagDyn integration: require login to access this tool. The bootstrap
// resolves to the parent app dir so this works regardless of how the
// tool is reached (direct or via iframe wrapper).
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Engineering Calculator · Training manual';
$current_page  = 'engineering-calculator-training.php';
$trigger_style = 'dark';
$cdn_scripts   = [];
include 'includes/head.php';
?>
<style>
/* ============ TRAINING DOC — MagDyn ============
   Styling mirrors the other tool manuals (bubble, weight) so all
   three read as one product family. Intentionally minimal — body
   prose + a few semantic blocks (callouts, terminal-ish formula
   listings, key-grid for shortcuts).
 ============================================== */
:root { --muted: var(--text-muted); }

body { overflow-x: hidden; }
.layout { min-height: 100vh; }

.sidebar { height: 100vh; position: sticky; top: 0; overflow-y: auto; }
.toc-heading {
    padding: 14px 16px 6px;
    font-size: 10px;
    color: var(--sidebar-text-very-dim);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 600;
}
.toc ol { list-style: none; padding: 4px 8px 16px; margin: 0; counter-reset: tocsec; }
.toc ol li { counter-increment: tocsec; }
.toc ol li a {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 12px; margin: 2px 0;
    color: var(--sidebar-text);
    font-size: 13px; border-radius: 6px;
    text-decoration: none;
    transition: background 0.12s, color 0.12s;
}
.toc ol li a::before {
    content: counter(tocsec, decimal-leading-zero);
    font-size: 10px; font-weight: 700;
    color: var(--sidebar-text-very-dim);
    letter-spacing: 0.05em;
    flex-shrink: 0; width: 18px;
}
.toc ol li a:hover { background: var(--sidebar-bg-hover); color: white; text-decoration: none; }
.toc ol li a:hover::before { color: rgba(255,255,255,0.6); }
.toc ol li a.active { background: var(--sidebar-bg-active); color: white; }
.toc ol li a.active::before { color: rgba(255,255,255,0.6); }

.main { padding: 32px 40px 60px; max-width: 880px; }

.hero { margin-bottom: 36px; padding-bottom: 24px; border-bottom: 1px solid var(--border); }
.hero .eyebrow {
    font-size: 11px; color: var(--primary);
    letter-spacing: 0.12em; text-transform: uppercase;
    font-weight: 700; margin-bottom: 12px;
}
.hero h1 {
    font-size: 32px; font-weight: 600;
    letter-spacing: -0.02em;
    margin: 0 0 14px;
    color: var(--text);
}
.hero h1 strong { color: var(--primary); font-weight: 700; }
.hero .lede { font-size: 15px; line-height: 1.7; color: var(--muted); max-width: 720px; }

section.module {
    margin: 0 0 48px;
    padding-top: 8px;
}
section.module h2 {
    font-size: 22px; font-weight: 600;
    margin: 0 0 14px;
    display: flex; align-items: baseline; gap: 14px;
}
section.module h2 .num {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 13px; font-weight: 700;
    color: var(--text-light);
    letter-spacing: 0.05em;
    background: var(--surface-alt);
    padding: 4px 8px; border-radius: 4px;
}
section.module h3 {
    font-size: 15px; font-weight: 600;
    margin: 22px 0 10px;
    color: var(--text);
}
section.module h4 {
    font-size: 12px; font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-light);
    margin: 18px 0 8px;
}
section.module p { line-height: 1.7; color: var(--text); margin-bottom: 12px; }
section.module p.dim { color: var(--muted); font-size: 13.5px; }

table {
    width: 100%; border-collapse: collapse;
    margin: 12px 0; font-size: 13px;
}
table th, table td {
    text-align: left;
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
}
table th {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--text-light);
    background: var(--surface-alt);
}
table td.dim { color: var(--muted); }
table td.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

.callout {
    margin: 16px 0;
    padding: 12px 14px;
    border-left: 3px solid var(--primary);
    background: var(--surface-alt);
    border-radius: 4px;
}
.callout .label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--primary);
    margin-bottom: 6px;
}
.callout p { margin: 0; font-size: 13.5px; line-height: 1.6; }
.callout.note { border-left-color: #6b7280; }
.callout.note .label { color: #6b7280; }
.callout.warn { border-left-color: #d97706; background: #fef3c7; }
.callout.warn .label { color: #b45309; }

.terminal {
    background: #1a1f2e;
    color: #cbd5e1;
    padding: 14px 18px;
    border-radius: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12.5px;
    line-height: 1.6;
    margin: 12px 0;
    white-space: pre-wrap;
}
.terminal .prompt { color: #94a3b8; display: block; margin-top: 4px; font-size: 11px; }
.terminal .out { color: #e2e8f0; display: block; padding-left: 4px; }

.steps { margin: 14px 0; }
.steps .step {
    display: flex; gap: 14px;
    padding: 12px 0;
    border-bottom: 1px dashed var(--border);
}
.steps .step:last-child { border-bottom: none; }
.steps .step-num {
    flex-shrink: 0;
    width: 24px; height: 24px;
    background: var(--primary); color: white;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    counter-increment: stepnum;
}
.steps .step-num::before { content: counter(stepnum); }
.steps { counter-reset: stepnum; }
.steps .step-body p { margin: 0 0 4px; }
.steps .step-body p.sub { font-size: 12.5px; color: var(--muted); line-height: 1.6; }

kbd {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
    padding: 2px 6px;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: 3px;
    color: var(--text);
}

code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12.5px;
    padding: 1px 4px;
    background: var(--surface-alt);
    border-radius: 3px;
    color: var(--text);
}

.foot {
    margin: 60px 0 20px;
    padding: 20px 0;
    border-top: 1px solid var(--border);
    display: flex; justify-content: space-between;
    font-size: 11.5px; color: var(--text-light);
}
</style>

<div class="layout">

<aside class="sidebar">
        
    <div class="brand">
        <div class="brand-mark">
            <div style="width:32px;height:32px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;letter-spacing:-0.02em;">MD</div>
        </div>
        <div class="brand-text">
            <div class="brand-title">Engineering Calculator</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#units">Unit Converter</a></li>
            <li><a href="#stackup">Tolerance Stack-up</a></li>
            <li><a href="#cpk">Cp / Cpk Capability</a></li>
            <li><a href="#fit">ISO 286 Fits</a></li>
            <li><a href="#sfm">Speeds &amp; Feeds</a></li>
            <li><a href="#geometry">Area · Volume · Weight</a></li>
            <li><a href="#sci">Scientific Calculator</a></li>
            <li><a href="#aql">IS 2500 Sampling</a></li>
            <li><a href="#iso2859">ISO 2859-1 Sampling</a></li>
            <li><a href="#history">Calculation History</a></li>
            <li><a href="#shortcuts">Keyboard Shortcuts</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Day-to-day engineering math</div>
    <h1>Nine calculators, one place, <strong>no spreadsheet juggling</strong>.</h1>
    <p class="lede">
        The MagDyn Engineering Calculator bundles the nine arithmetic
        tasks that come up most often around a machine shop and inspection
        room: unit conversion, tolerance stack-up, process capability
        (Cp/Cpk), ISO 286 fits, speeds &amp; feeds, area-volume-weight
        of stock, a general scientific calculator, and two sampling-plan
        lookups. Everything runs in your browser; nothing uploads.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    The calculator is a single page with nine views accessible from
    the left rail (or in the embedded MagDyn sidebar, from the
    sub-entries under Tools → Engineering Calculator). Each view is
    independent — switching to a different calculator preserves the
    state of the one you left, so you can flip back to it without
    losing your inputs.
  </p>

  <h3>Common patterns across all calculators</h3>
  <table>
    <thead><tr><th style="width:28%">FEATURE</th><th>WHAT IT DOES</th></tr></thead>
    <tbody>
      <tr><td>Live computation</td><td class="dim">Type into any input and the dependent fields update immediately. No "Compute" button is required for routine use — outputs always reflect the current inputs.</td></tr>
      <tr><td>Calculate &amp; save (<kbd>Enter</kbd>)</td><td class="dim">The primary button on each panel saves the current computation to that calculator's <em>history</em>, so you can recall it later. Same as pressing <kbd>Enter</kbd> in any input.</td></tr>
      <tr><td>Per-calculator history</td><td class="dim">Recent calculations show below the inputs, newest first. Each entry can be re-loaded into the inputs with a single click, or copied as a one-line summary.</td></tr>
      <tr><td>Local-only storage</td><td class="dim">All history lives in your browser's <code>localStorage</code>. Closing the tab keeps the history; clearing browser data wipes it. No server round-trip.</td></tr>
      <tr><td>Unit-aware where it matters</td><td class="dim">Stock weight, speeds &amp; feeds, and fits accept inputs in mm or inch — pick once per panel, results follow.</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">SCOPE</div>
    <p>The calculator is built for routine engineering arithmetic: things you'd otherwise compute in a spreadsheet or on a notepad. For serious FEA, root-cause analysis, or statistical modeling beyond Cp/Cpk, use dedicated tools.</p>
  </div>
</section>

<!-- ============ UNIT CONVERTER ============ -->
<section class="module" id="units">
  <h2><span class="num">02</span> Unit Converter</h2>

  <p>
    Eight categories, paired conversion fields, plus a "common
    conversions" table that lists everyday equivalents at a glance.
    Live update on either side — type into A and B follows, or type
    into B and A follows.
  </p>

  <h3>Supported categories</h3>
  <table>
    <thead><tr><th style="width:24%">CATEGORY</th><th>UNITS</th></tr></thead>
    <tbody>
      <tr><td>Length</td><td class="dim">mm, cm, m, km, in, ft, yd, mile, µm, mil, thou</td></tr>
      <tr><td>Area</td><td class="dim">mm², cm², m², km², in², ft², yd², acre, hectare</td></tr>
      <tr><td>Volume</td><td class="dim">mm³, cm³ (cc), m³, L, mL, in³, ft³, US gal, UK gal, oz (fluid)</td></tr>
      <tr><td>Mass</td><td class="dim">g, kg, t (tonne), mg, lb, oz, ton (US short), ton (UK long)</td></tr>
      <tr><td>Force</td><td class="dim">N, kN, MN, kgf, lbf, dyne</td></tr>
      <tr><td>Pressure</td><td class="dim">Pa, kPa, MPa, GPa, bar, psi, ksi, atm, mmHg, inHg</td></tr>
      <tr><td>Angle</td><td class="dim">deg, rad, gradian, turn, arcmin, arcsec</td></tr>
      <tr><td>Temperature</td><td class="dim">°C, °F, K (affine — not just a multiplier)</td></tr>
    </tbody>
  </table>

  <h3>How to use it</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick a category</strong> from the dropdown — Length, Mass, Pressure, etc.</p>
        <p class="sub">The two unit dropdowns repopulate with that category's units.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick "from" and "to" units</strong>, type a value into either input.</p>
        <p class="sub">The other input updates as you type. The "Common conversions for this value" panel on the right shows the same value in every unit at once.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "Calculate &amp; save"</strong> (or press <kbd>Enter</kbd>) to add the conversion to history.</p>
      </div>
    </div>
  </div>

  <div class="callout">
    <div class="label">TEMPERATURE NUANCE</div>
    <p>Unlike most categories, temperature isn't a simple multiplier — °F to °C requires subtracting 32 and dividing by 1.8 (plus the absolute-zero offset for K). The converter handles this correctly, but be aware: "5° more" in °C is <em>not</em> "5° more" in °F. For deltas (e.g., temperature rise), convert the difference directly rather than the endpoint temperatures.</p>
  </div>
</section>

<!-- ============ STACK-UP ============ -->
<section class="module" id="stackup">
  <h2><span class="num">03</span> Tolerance Stack-up</h2>

  <p>
    Compute the cumulative effect of multiple toleranced dimensions in
    a chain — does the total fit within the gap available? The tool
    handles both worst-case and root-sum-square (RSS) analysis side
    by side, and lets you flip signs per dimension for chains where
    some dimensions add and others subtract.
  </p>

  <h3>Worst case vs RSS — which to use</h3>
  <p>
    Worst case (WC) sums absolute tolerances — every dimension at its
    extreme simultaneously. Guarantees no assembly will fail, but is
    pessimistic: in real production almost no assembly has every
    dimension at the extreme at once.
  </p>
  <p>
    Root-sum-square (RSS) assumes each dimension follows a normal
    distribution centered on nominal, with σ at 1/3 the tolerance band
    (so the spec is ±3σ). The stack-up's σ is √(Σσᵢ²) and the total
    range at ±3σ is √(Σtolᵢ²). Predicts roughly 99.73% of assemblies
    fall in range — small probability of a failure but statistically
    realistic.
  </p>

  <table>
    <thead><tr><th style="width:32%">SITUATION</th><th>METHOD</th></tr></thead>
    <tbody>
      <tr><td>Safety-critical, low-volume</td><td class="dim">Worst case. A single failure isn't acceptable.</td></tr>
      <tr><td>Customer requires zero defects (medical, aerospace)</td><td class="dim">Worst case.</td></tr>
      <tr><td>High-volume, SPC-controlled production</td><td class="dim">RSS. Failures are rare and economic-acceptable.</td></tr>
      <tr><td>Mass-market consumer goods</td><td class="dim">RSS (with appropriate Cpk targets).</td></tr>
      <tr><td>Prototype or feasibility check</td><td class="dim">Both — see the relative spread to know which constraint you're up against.</td></tr>
    </tbody>
  </table>

  <h3>Direction sign (+ / −)</h3>
  <p>
    The Dir column lets each dimension contribute positively or
    negatively to the chain. Typical case: a feature that must fit
    inside a pocket — pocket depth contributes +, part height
    contributes −, the remainder is the gap. The total stack is
    Σ(dirᵢ · nominalᵢ).
  </p>

  <h3>The formula at a glance</h3>
  <div class="terminal">
    <span class="prompt">Sum (mean gap)</span>
    <span class="out">Σ (dir<sub>i</sub> · nominal<sub>i</sub>)</span>
    <span class="prompt">Worst case spread</span>
    <span class="out">WC = Σ |tol<sub>i</sub>|</span>
    <span class="prompt">RSS spread</span>
    <span class="out">RSS = √( Σ tol<sub>i</sub>² )</span>
    <span class="prompt">Range (either method)</span>
    <span class="out">[ Sum − spread,  Sum + spread ]</span>
  </div>

  <div class="callout note">
    <div class="label">ABOUT BILATERAL TOLERANCES</div>
    <p>The stack-up assumes <em>bilateral</em> tolerances — Tol+ and Tol− on each side of the nominal. For unilateral tolerances (e.g., 10.00 +0.05/-0.00) just enter 0.05 in Tol+ and 0.00 in Tol−. The math still works.</p>
  </div>
</section>

<!-- ============ Cp / Cpk ============ -->
<section class="module" id="cpk">
  <h2><span class="num">04</span> Cp / Cpk Capability</h2>

  <p>
    Process capability indices summarize how well a process can hold
    a tolerance band. Higher numbers mean more headroom; numbers below
    1.0 mean the process is producing out-of-spec parts at a non-trivial
    rate. Cp/Cpk are short-term measures (within-batch); Pp/Ppk are
    long-term equivalents.
  </p>

  <h3>The four indices</h3>
  <table>
    <thead><tr><th style="width:14%">INDEX</th><th style="width:32%">FORMULA</th><th>WHAT IT TELLS YOU</th></tr></thead>
    <tbody>
      <tr><td><strong>Cp</strong></td><td class="mono">(USL − LSL) / 6σ</td><td class="dim">How wide the tolerance is vs how wide the process is. Ignores whether the process is centered. Cp ≥ 1.33 is typically acceptable.</td></tr>
      <tr><td><strong>Cpk</strong></td><td class="mono">min[(USL − μ) / 3σ, (μ − LSL) / 3σ]</td><td class="dim">Same idea but accounts for off-center processes. Cpk = Cp when perfectly centered, lower otherwise.</td></tr>
      <tr><td><strong>Pp</strong></td><td class="mono">(USL − LSL) / 6σ<sub>long</sub></td><td class="dim">Long-term equivalent of Cp. σ<sub>long</sub> includes between-batch variation.</td></tr>
      <tr><td><strong>Ppk</strong></td><td class="mono">min[(USL − μ) / 3σ<sub>long</sub>, (μ − LSL) / 3σ<sub>long</sub>]</td><td class="dim">Long-term Cpk. The number your customer probably cares about.</td></tr>
    </tbody>
  </table>

  <h3>Defect rate (PPM)</h3>
  <p>
    The PPM (parts per million defective) output assumes the process is
    normally distributed. For Cpk = 1.0 expect about 2700 PPM. For
    Cpk = 1.33 expect about 64 PPM. For Cpk = 1.67 (Six Sigma's
    "short-term" target) expect about 0.6 PPM.
  </p>

  <h3>How to use it</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Enter USL and LSL</strong> — the upper and lower spec limits from your drawing.</p>
        <p class="sub">For unilateral specs (one-sided), put the missing limit empty; Cpk will compute from the side that's specified.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Enter the process mean and standard deviation</strong> from your measurement data.</p>
        <p class="sub">Mean and σ are usually computed from your CMM output or SPC chart. The calculator doesn't do that step — feed it the already-computed statistics.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p>The result panel shows <strong>Cp · Cpk · Pp · Ppk · PPM</strong> updated live. A coloured indicator flags whether each index is below 1.0 (red), between 1.0 and 1.33 (yellow), or above 1.33 (green).</p>
      </div>
    </div>
  </div>

  <div class="callout warn">
    <div class="label">ASSUMES NORMAL DISTRIBUTION</div>
    <p>Cp/Cpk math assumes a normal distribution. If your process produces non-normal data (heavy skew, bimodal, truncated), the indices will misrepresent the actual defect rate. For non-normal processes, use a percentile-based capability index (Cp<sub>k</sub>(P)) or transform the data first.</p>
  </div>
</section>

<!-- ============ FITS ============ -->
<section class="module" id="fit">
  <h2><span class="num">05</span> ISO 286 Fits / Clearances</h2>

  <p>
    Pick a hole tolerance class and a shaft tolerance class; the
    calculator returns the maximum clearance, minimum clearance (or
    maximum interference), and classifies the fit as clearance,
    transition, or interference.
  </p>

  <h3>The ISO 286 system</h3>
  <p>
    ISO 286 standardises hole and shaft tolerances into letter-grade
    pairs. The letter signals the position relative to nominal (H is
    "hole, lower limit at zero"; h is "shaft, upper limit at zero"),
    and the IT number signals the magnitude (IT6 is tight, IT11 is
    loose). Common everyday fits use H7/h6, H7/g6 (running fit),
    H7/p6 (light press), H7/s6 (medium press).
  </p>

  <table>
    <thead><tr><th style="width:24%">FIT TYPE</th><th>WHAT IT MEANS</th></tr></thead>
    <tbody>
      <tr><td>Clearance</td><td class="dim">Minimum clearance ≥ 0. Shaft always slides freely in hole. Examples: bearings, sliding pins.</td></tr>
      <tr><td>Transition</td><td class="dim">Minimum clearance is slightly negative, maximum is slightly positive. Some assemblies need press-fit, some slide. Examples: light interference dowel pins.</td></tr>
      <tr><td>Interference</td><td class="dim">Maximum clearance &lt; 0. Always requires force to assemble. Examples: press-fit bushings, shrink fits.</td></tr>
    </tbody>
  </table>

  <h3>How to use it</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Enter the nominal diameter</strong> (the basic size both hole and shaft target).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick a hole tolerance class</strong> (e.g., H7, G6) and <strong>shaft tolerance class</strong> (e.g., h6, g5).</p>
        <p class="sub">The dropdowns are populated with the common classes from the ISO 286 table. Standard pairings (H7/h6, H7/g6, H7/p6, etc.) are easy to find at the top.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p>The result panel shows <strong>hole and shaft tolerance ranges</strong>, <strong>maximum and minimum clearance/interference</strong>, and the <strong>fit type</strong> badge.</p>
      </div>
    </div>
  </div>

  <div class="callout note">
    <div class="label">DIAMETER STEPS</div>
    <p>ISO 286 tolerance values change in steps as nominal diameter increases (e.g., 3–6mm uses one set of values, 6–10mm uses another). The calculator picks the right step automatically based on the nominal you enter; you don't need to look up the bracket yourself.</p>
  </div>
</section>

<!-- ============ SPEEDS & FEEDS ============ -->
<section class="module" id="sfm">
  <h2><span class="num">06</span> Speeds &amp; Feeds</h2>

  <p>
    Compute spindle RPM and feed rate for a given material, tool
    diameter, and cutting operation. The result is a starting point —
    real shop values depend on coolant, rigidity, tool life targets,
    and the specific tool grade.
  </p>

  <h3>Inputs</h3>
  <table>
    <thead><tr><th style="width:24%">INPUT</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Material</td><td class="dim">Steel (mild / medium-carbon / alloy / stainless), aluminum, brass, copper, cast iron, titanium, plastic. Each has a baseline SFM (surface feet per minute) recommendation.</td></tr>
      <tr><td>Tool diameter</td><td class="dim">In inches or mm, your choice.</td></tr>
      <tr><td>Operation</td><td class="dim">Turning, milling, drilling, reaming. Different operations have different per-flute feeds.</td></tr>
      <tr><td>Number of flutes</td><td class="dim">For milling and drilling. The feed per tooth × flutes × RPM gives the feed rate.</td></tr>
    </tbody>
  </table>

  <h3>Outputs</h3>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li><strong>RPM</strong> from SFM and tool diameter: <code>RPM = (SFM × 12) / (π × D)</code> for imperial; <code>RPM = (SFM × 1000) / (π × D)</code> for metric.</li>
    <li><strong>Feed rate (IPM or mm/min)</strong> from feed per tooth × flutes × RPM.</li>
    <li><strong>Material removal rate (MRR)</strong> in in³/min or cm³/min — useful for comparing tools.</li>
  </ul>

  <div class="callout warn">
    <div class="label">STARTING POINTS ONLY</div>
    <p>The numbers from this calculator are general SFM recommendations — they're not tuned to a specific tool grade, coating, or coolant. Always start lower and ramp up while watching surface finish and tool temperature. For long production runs, request a recommended SFM/feed from the tool manufacturer.</p>
  </div>
</section>

<!-- ============ GEOMETRY ============ -->
<section class="module" id="geometry">
  <h2><span class="num">07</span> Area · Volume · Weight</h2>

  <p>
    Pick a shape (round, square, hex, plate, tube, sheet), pick a
    material, enter dimensions — the calculator returns surface area,
    volume, and total weight. Useful for cost estimation, freight,
    and verifying material take-off against drawings.
  </p>

  <h3>Supported shapes</h3>
  <table>
    <thead><tr><th style="width:26%">SHAPE</th><th>WHAT TO ENTER</th></tr></thead>
    <tbody>
      <tr><td>Round bar</td><td class="dim">Diameter, length</td></tr>
      <tr><td>Square bar</td><td class="dim">Side, length</td></tr>
      <tr><td>Rectangular bar</td><td class="dim">Width, height, length</td></tr>
      <tr><td>Hexagonal bar</td><td class="dim">Across-flats, length</td></tr>
      <tr><td>Plate</td><td class="dim">Length, width, thickness</td></tr>
      <tr><td>Tube (round)</td><td class="dim">OD, wall thickness, length</td></tr>
      <tr><td>Tube (square)</td><td class="dim">Side, wall thickness, length</td></tr>
      <tr><td>Sheet</td><td class="dim">Length, width, gauge (or thickness)</td></tr>
    </tbody>
  </table>

  <h3>Material library</h3>
  <p>
    The dropdown lists about thirty common engineering materials with
    typical densities — carbon steel grades (1018, 1045, 4140, 4340),
    stainless steels (304, 316, 17-4PH), aluminum alloys (1100, 2024,
    5052, 6061, 7075), brass and bronze grades, plastics (HDPE, PTFE,
    Delrin, nylon, PEEK), and a handful of specialty materials. If
    your alloy isn't listed, pick "Custom density" and enter your own.
  </p>

  <h3>Cost estimation</h3>
  <p>
    Enter a price per unit weight (e.g., $/lb or $/kg) and the
    calculator multiplies by total weight to give an indicative cost.
    Useful for quote work; not a substitute for an actual quote from
    your stock supplier.
  </p>
</section>

<!-- ============ SCIENTIFIC ============ -->
<section class="module" id="sci">
  <h2><span class="num">08</span> Scientific Calculator</h2>

  <p>
    A general-purpose calculator with trig, log, exponent, and
    parenthesis support. Useful for one-off arithmetic that doesn't
    fit one of the specialized calculators above. Expression-style
    input (you type a full expression like
    <code>sin(45 deg) * sqrt(2)</code> and hit equals) rather than the
    classic chain-of-operations style.
  </p>

  <h3>Supported operations</h3>
  <table>
    <thead><tr><th style="width:30%">CATEGORY</th><th>OPERATIONS</th></tr></thead>
    <tbody>
      <tr><td>Arithmetic</td><td class="mono">+, −, ×, ÷, % (modulo), x^y (power), √</td></tr>
      <tr><td>Trigonometry</td><td class="mono">sin, cos, tan, asin, acos, atan</td></tr>
      <tr><td>Logarithm / exponent</td><td class="mono">ln (natural), log (base 10), exp, e^x</td></tr>
      <tr><td>Constants</td><td class="mono">π, e</td></tr>
      <tr><td>Grouping</td><td class="mono">( ), nested any depth</td></tr>
      <tr><td>Memory</td><td class="dim">MC, MR, M+, M− buttons or keyboard shortcuts</td></tr>
    </tbody>
  </table>

  <h3>Degrees vs radians</h3>
  <p>
    A toggle in the panel header sets the angle mode for trig functions.
    The display under the mode toggle reminds you which mode is active —
    a common error in shop calculations is computing
    <code>sin(45)</code> in radian mode (which returns 0.851) instead
    of degree mode (which returns 0.707).
  </p>

  <div class="callout">
    <div class="label">EXPRESSIONS, NOT KEY-AT-A-TIME</div>
    <p>Unlike a four-function calculator, this one parses full expressions. <code>2 + 3 * 4</code> returns 14 (multiplication first), not 20. Use parentheses to force order: <code>(2 + 3) * 4</code> returns 20.</p>
  </div>
</section>

<!-- ============ AQL / IS 2500 ============ -->
<section class="module" id="aql">
  <h2><span class="num">09</span> IS 2500 Sampling Plan</h2>

  <p>
    The Indian Standard sampling plan (IS 2500-1, equivalent in
    structure to ISO 2859) returns sample sizes and accept/reject
    counts for a given lot size, AQL, and inspection level. Used in
    incoming inspection and FAI sampling to decide how many parts to
    measure from a delivered lot.
  </p>

  <h3>Inputs</h3>
  <table>
    <thead><tr><th style="width:26%">INPUT</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Lot size</td><td class="dim">The size of the delivered batch — the population the sample is drawn from.</td></tr>
      <tr><td>AQL</td><td class="dim">Acceptable Quality Level: the maximum fraction defective considered acceptable in the long run, in percent (e.g., 1.0, 2.5, 4.0). Lower AQL = stricter.</td></tr>
      <tr><td>Inspection level</td><td class="dim">Normal, Tightened, or Reduced. Tightened is used after a string of rejected lots, Reduced after a string of accepted lots. Normal is the starting point.</td></tr>
      <tr><td>Inspection severity</td><td class="dim">General levels I/II/III (II is standard) or special levels S-1 through S-4 for inspections where small samples are expected.</td></tr>
    </tbody>
  </table>

  <h3>Outputs</h3>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li><strong>Sample size</strong> — how many parts to measure.</li>
    <li><strong>Accept</strong> — maximum defectives allowed in the sample for the lot to pass.</li>
    <li><strong>Reject</strong> — minimum defectives that cause the lot to fail. (Reject = Accept + 1 in single sampling.)</li>
  </ul>

  <div class="callout note">
    <div class="label">SAMPLING ISN'T MAGIC</div>
    <p>The accept/reject criterion balances producer's risk and consumer's risk for a typical OC curve. If your customer's contract specifies a different sampling plan (some specify ANSI Z1.4, others MIL-STD-1916, others industry-specific), use the plan they require — don't substitute IS 2500 just because it's convenient.</p>
  </div>
</section>

<!-- ============ ISO 2859-1 ============ -->
<section class="module" id="iso2859">
  <h2><span class="num">10</span> ISO 2859-1 Sampling Plan</h2>

  <p>
    The international equivalent of IS 2500. Same inputs (lot size,
    AQL, inspection level, severity), same output shape. Different
    code-letter table internally, but the user-facing flow is identical
    to the IS 2500 calculator above.
  </p>

  <h3>When to use which</h3>
  <table>
    <thead><tr><th style="width:30%">SCENARIO</th><th>USE</th></tr></thead>
    <tbody>
      <tr><td>Customer in India</td><td class="dim">IS 2500-1</td></tr>
      <tr><td>Customer in EU, US, or international supply</td><td class="dim">ISO 2859-1</td></tr>
      <tr><td>Customer specifies ANSI/ASQ Z1.4</td><td class="dim">ISO 2859-1 (Z1.4 and ISO 2859-1 are numerically equivalent)</td></tr>
      <tr><td>Customer specifies MIL-STD-105E</td><td class="dim">ISO 2859-1 — MIL-STD-105E was withdrawn in 1995 and ISO 2859-1 replaces it numerically.</td></tr>
      <tr><td>Internal inspection with no customer mandate</td><td class="dim">Either — they give the same numbers for the same AQL/level combo.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ HISTORY ============ -->
<section class="module" id="history">
  <h2><span class="num">11</span> Calculation History</h2>

  <p>
    Every calculator has its own history pane below the inputs. Hitting
    "Calculate &amp; save" (or pressing <kbd>Enter</kbd>) adds the
    current computation. Each entry is timestamped and clickable —
    clicking re-loads its inputs into the panel above so you can
    continue from where you left off, or duplicate a run with a small
    change.
  </p>

  <h3>What's stored</h3>
  <table>
    <thead><tr><th style="width:28%">PER ENTRY</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>All inputs</td><td class="dim">Exactly what you typed, including units and category selections.</td></tr>
      <tr><td>Computed outputs</td><td class="dim">The result fields at the moment of save.</td></tr>
      <tr><td>Timestamp</td><td class="dim">Local time, browser clock.</td></tr>
      <tr><td>One-line summary</td><td class="dim">A compact representation suitable for copying. The Copy button on each entry copies this to clipboard.</td></tr>
    </tbody>
  </table>

  <h3>Where it lives</h3>
  <p>
    All history is stored in your browser's <code>localStorage</code>
    under per-calculator keys (<code>magdyn.calc.units.history</code>,
    <code>magdyn.calc.stackup.history</code>, etc.). It survives tab
    close but disappears if you clear your browser data. Each
    calculator's history caps at the most recent 50 entries — older
    ones drop off.
  </p>

  <div class="callout warn">
    <div class="label">NOT A SAVING MECHANISM</div>
    <p>Don't rely on history as durable storage. It's session-bound. For records you need to keep (FAI reports, customer submissions, audit trails), copy the result out of the calculator into a proper document — the Copy button on each history row gives you a compact one-liner suitable for pasting into a report or email.</p>
  </div>
</section>

<!-- ============ SHORTCUTS ============ -->
<section class="module" id="shortcuts">
  <h2><span class="num">12</span> Keyboard Shortcuts</h2>

  <h4>GLOBAL</h4>
  <table>
    <thead><tr><th style="width:22%">KEY</th><th>ACTION</th></tr></thead>
    <tbody>
      <tr><td><kbd>Enter</kbd></td><td class="dim">Calculate &amp; save in the currently active panel.</td></tr>
      <tr><td><kbd>Esc</kbd></td><td class="dim">Defocus the current input. Useful before clicking elsewhere.</td></tr>
    </tbody>
  </table>

  <h4>SCIENTIFIC CALCULATOR</h4>
  <table>
    <thead><tr><th style="width:22%">KEY</th><th>ACTION</th></tr></thead>
    <tbody>
      <tr><td><kbd>0-9</kbd></td><td class="dim">Type digits</td></tr>
      <tr><td><kbd>+</kbd> <kbd>−</kbd> <kbd>*</kbd> <kbd>/</kbd></td><td class="dim">Arithmetic operators</td></tr>
      <tr><td><kbd>(</kbd> <kbd>)</kbd></td><td class="dim">Grouping</td></tr>
      <tr><td><kbd>.</kbd></td><td class="dim">Decimal point</td></tr>
      <tr><td><kbd>Enter</kbd> or <kbd>=</kbd></td><td class="dim">Evaluate the expression</td></tr>
      <tr><td><kbd>Backspace</kbd></td><td class="dim">Delete last character</td></tr>
      <tr><td><kbd>Esc</kbd> or <kbd>c</kbd></td><td class="dim">Clear expression</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">13</span> Troubleshooting</h2>

  <h3>I switched calculators and lost my inputs</h3>
  <p>
    You shouldn't have — each calculator preserves its state when you
    leave and return. If a state was lost, the most likely cause is
    a page reload (F5 or browser back/forward). Calculator state lives
    in DOM, not localStorage, so a reload wipes it. The <em>history</em>
    is in localStorage and survives reloads.
  </p>

  <h3>Cpk says 1.33 but my customer is rejecting parts</h3>
  <p>
    Three usual suspects: (1) your process isn't normally distributed —
    Cpk math assumes normality and underestimates failure rate for
    skewed processes; (2) you computed σ from a too-small sample
    and the true population σ is larger; (3) your customer measures
    a different feature than you do, or uses different gauging. Look
    at the raw data shape (a histogram) before blaming math.
  </p>

  <h3>Speeds &amp; Feeds gives me RPM but my machine cuts at half that with no problems</h3>
  <p>
    The SFM values built into the tool are general engineering
    recommendations — pessimistic on the slow side for tool life,
    optimistic on the fast side for surface finish. Modern coated
    tools and rigid machines can often exceed the recommended SFM
    by 50-100% on good days. Treat the calculator output as a
    starting point and ramp up while watching surface finish, tool
    temperature, and chip formation.
  </p>

  <h3>The history is gone after I cleared cookies</h3>
  <p>
    History lives in browser localStorage. Some browsers' "clear
    cookies" option also clears localStorage (especially if you
    selected "site data" rather than just cookies). It's not
    recoverable. For records you need to keep, copy the result
    out of the calculator into a proper document at the time of
    computation.
  </p>

  <h3>ISO 286 fit calculator says "transition" but I want a slip fit</h3>
  <p>
    You picked tolerance classes that overlap the zero line. For
    a strictly clearance fit (always slides), pick a "lower" shaft
    class — e.g. H7/g6 instead of H7/h6. The fit-type badge updates
    immediately as you change the dropdowns; you can experiment
    until the badge says "Clearance" before noting down the pair.
  </p>

  <h3>I want to bookmark a specific calculator panel</h3>
  <p>
    The Engineering Calculator is a single page with view-switching;
    individual sub-tools don't have their own URLs in the embedded
    SPA experience. The MagDyn sidebar entries for each sub-tool
    DO navigate via the <code>?view=</code> URL parameter, so you
    can bookmark e.g. <code>/tools.php?tool=calc&amp;view=cpk</code>
    to land on the Cpk view directly.
  </p>
</section>

<div class="foot">
    <div>Engineering Calculator · Operator Manual · v1.0</div>
    <div>Runs locally · No telemetry</div>
</div>

</main>

</div>

<script>
    // Highlight active section in TOC as user scrolls
    const links = document.querySelectorAll('nav.toc a');
    const sections = Array.from(links).map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.id;
                links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + id));
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });
    sections.forEach(s => observer.observe(s));
</script>

<?php include 'includes/footer.php'; ?>
