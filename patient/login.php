<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.use_strict_mode', '1');

if (empty($_SERVER['HTTPS'])) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/tenant.php';

$error = $_SESSION['patient_login_error'] ?? '';
unset($_SESSION['patient_login_error']);

if (isset($_SESSION['patient_id'])) {
    header('Location: /patient/dashboard.php');
    exit;
}
?>

<!DOCTYPE html>

<html lang="en">

<head>
<meta charset="UTF-8">

<title>Patient Portal | WebSplend HMS</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    name="description"
    content="Secure WebSplend HMS Patient Portal"
>

<link
    rel="icon"
    href="/assets/img/hms_logo.png"
    type="image/png"
>

<style>

    :root {
        --primary: #099aa7;
        --primary-dark: #087d87;
        --primary-deep: #056871;

        --heading: #173437;
        --text: #34494c;
        --muted: #77898c;

        --border: #dfe8e9;

        --white: #ffffff;

        --light: #f5fafb;
    }


    * {
        box-sizing: border-box;
    }


    html,
    body {
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
    }


    body {

        font-family:
            Inter,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            Roboto,
            Arial,
            sans-serif;

        color: var(--text);

        background: #f4f9fa;

        overflow-x: hidden;
    }


    /* =========================================================
       PAGE
    ========================================================= */
    .portal-page {
        min-height: 100vh;
        display: grid;
        grid-template-columns: 58% 42%;
    }
    /* =========================================================
       LEFT SIDE
    ========================================================= */
    .visual-section {
        position: relative;
        min-height: 100vh;
        overflow: hidden;
        color: #ffffff;
        background:
            linear-gradient(
                135deg,
                #056c75 0%,
                #078c97 45%,
                #099aa7 100%
            );
    }
    /* Decorative circles */
    .visual-section::before {
        content: "";
        position: absolute;
        width: 700px;
        height: 700px;
        border-radius: 50%;
        border: 1px solid rgba(255,255,255,.10);
        right: -340px;
        top: -260px;
    }
    .visual-section::after {
        content: "";
        position: absolute;
        width: 500px;
        height: 500px;
        border-radius: 50%;
        border: 1px solid rgba(255,255,255,.08);
        left: -280px;
        bottom: -250px;
    }
    .circle-one {
        position: absolute;
        width: 350px;
        height: 350px;
        border-radius: 50%;
        background: rgba(255,255,255,.035);
        right: 15%;
        bottom: 8%;
    }
    .circle-two {
        position: absolute;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        background: rgba(255,255,255,.04);
        left: 35%;
        top: 12%;
    }
    /* =========================================================
       VISUAL CONTENT
    ========================================================= */
    .visual-content {
        position: relative;
        z-index: 5;
        min-height: 100vh;
        padding:
            42px
            clamp(45px, 6vw, 110px)
            38px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    /* Brand */
    .brand {
        display: flex;
        align-items: center;
        gap: 13px;
    }
    .brand-logo {
        width: 65px;
        height: 65px;
        padding: 7px;
        object-fit: contain;
        background: rgba(255,255,255,.97);
        border-radius: 15px;
        box-shadow:
            0 10px 30px rgba(0,0,0,.12);
    }
    .brand-text strong {
        display: block;
        font-size: 18px;
        line-height: 1.2;
        letter-spacing: -.3px;
    }
    .brand-text span {
        display: block;
        margin-top: 3px;
        font-size: 11px;
        color: rgba(255,255,255,.70);
        letter-spacing: .2px;
    }
    /* Main introduction */
    .visual-main {
        max-width: 690px;
        margin-top: -30px;
    }
    .welcome-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 13px;
        border-radius: 30px;
        background: rgba(255,255,255,.10);
        border:
            1px solid rgba(255,255,255,.15);
        font-size: 11px;
        font-weight: 650;
        letter-spacing: .2px;
        backdrop-filter: blur(10px);
    }
    .status-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #c6f7fa;
        box-shadow:
            0 0 0 4px rgba(198,247,250,.12);
    }
    .visual-title {
        margin: 22px 0 0;
        font-size:
            clamp(42px, 5vw, 68px);

        line-height: 1.03;

        font-weight: 750;

        letter-spacing: -2.7px;
    }


    .visual-title span {

        color: #baf1f4;
    }


    .visual-description {

        max-width: 570px;

        margin: 25px 0 0;

        font-size: 16px;

        line-height: 1.75;

        color: rgba(255,255,255,.80);
    }


    /* Feature cards */

    .feature-grid {

        display: grid;

        grid-template-columns:
            repeat(3, minmax(0, 1fr));

        gap: 12px;

        margin-top: 40px;

        max-width: 690px;
    }


    .feature-card {

        min-height: 105px;

        padding: 17px;

        border-radius: 15px;

        background:
            rgba(255,255,255,.085);

        border:
            1px solid rgba(255,255,255,.11);

        backdrop-filter: blur(12px);
    }


    .feature-icon {

        width: 32px;
        height: 32px;

        display: flex;

        align-items: center;
        justify-content: center;

        border-radius: 9px;

        background:
            rgba(255,255,255,.13);

        margin-bottom: 12px;
    }


    .feature-icon svg {

        width: 17px;
        height: 17px;
    }


    .feature-card strong {

        display: block;

        font-size: 12px;

        font-weight: 650;
    }


    .feature-card span {

        display: block;

        margin-top: 4px;

        font-size: 10px;

        line-height: 1.4;

        color: rgba(255,255,255,.62);
    }


    /* Bottom */

    .visual-footer {

        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 20px;

        color: rgba(255,255,255,.53);

        font-size: 10px;
    }


    .secure-label {

        display: flex;

        align-items: center;

        gap: 7px;
    }


    .secure-label svg {

        width: 14px;
        height: 14px;
    }


    /* =========================================================
       LOGIN SIDE
    ========================================================= */

    .login-section {

        min-height: 100vh;

        background: #ffffff;

        display: flex;

        align-items: center;

        justify-content: center;

        padding:
            20px
            clamp(35px, 5vw, 90px);
    }


    .login-container {

        width: 100%;

        max-width: 450px;
    }


    /* Login heading */

    .login-top {

        margin-bottom: 34px;
    }


    .login-symbol {

        width: 53px;
        height: 53px;

        display: flex;

        align-items: center;
        justify-content: center;

        border-radius: 15px;

        background:
            #e9f8fa;

        color: var(--primary);

        margin-bottom: 21px;
    }


    .login-symbol svg {

        width: 25px;
        height: 25px;
    }


    .login-title {

        margin: 0;

        color: var(--heading);

        font-size: 31px;

        line-height: 1.15;

        font-weight: 750;

        letter-spacing: -1px;
    }


    .login-subtitle {

        margin: 10px 0 0;

        color: var(--muted);

        font-size: 14px;

        line-height: 1.65;
    }


    /* Error */

    .error {

        display: flex;

        align-items: flex-start;

        gap: 10px;

        margin-bottom: 23px;

        padding: 13px 14px;

        border-radius: 11px;

        background: #fff2f2;

        border: 1px solid #f2cccc;

        color: #b32626;

        font-size: 12px;

        line-height: 1.5;
    }


    .error svg {

        width: 17px;
        height: 17px;

        flex: 0 0 auto;

        margin-top: 1px;
    }


    /* Form */

    .form-group {

        margin-bottom: 20px;
    }


    .form-label {

        display: flex;

        align-items: center;

        justify-content: space-between;

        margin-bottom: 8px;

        font-size: 12px;

        font-weight: 700;

        color: #34494c;
    }


    .input-wrapper {

        position: relative;
    }


    .input-icon {

        position: absolute;

        left: 15px;

        top: 50%;

        transform: translateY(-50%);

        width: 18px;
        height: 18px;

        color: #8ca0a3;

        pointer-events: none;
    }


    input {

        width: 100%;

        height: 53px;

        border: 1px solid var(--border);

        border-radius: 11px;

        background: #fbfdfd;

        color: var(--heading);

        font-family: inherit;

        font-size: 14px;

        padding:
            0
            45px
            0
            44px;

        outline: none;

        transition:
            border .2s ease,
            box-shadow .2s ease,
            background .2s ease;
    }


    input:hover {

        border-color: #c7d6d8;
    }


    input:focus {

        background: #ffffff;

        border-color: var(--primary);

        box-shadow:
            0 0 0 4px rgba(9,154,167,.09);
    }


    input::placeholder {

        color: #a1afb1;
    }


    /* Password */

    .password-toggle {

        position: absolute;

        right: 8px;

        top: 50%;

        transform: translateY(-50%);

        width: 37px;
        height: 37px;

        padding: 0;

        border: 0;

        background: transparent;

        color: #91a0a2;

        display: flex;

        align-items: center;

        justify-content: center;

        cursor: pointer;

        border-radius: 8px;
    }


    .password-toggle:hover {

        color: var(--primary);

        background: #edf8f9;
    }


    .password-toggle svg {

        width: 17px;
        height: 17px;
    }


    /* Login button */

    .login-button {

        width: 100%;

        height: 54px;

        margin-top: 4px;

        border: 0;

        border-radius: 11px;

        background:
            linear-gradient(
                135deg,
                #099aa7,
                #087f89
            );

        color: #ffffff;

        font-family: inherit;

        font-size: 13px;

        font-weight: 700;

        cursor: pointer;

        display: flex;

        align-items: center;

        justify-content: center;

        gap: 10px;

        box-shadow:
            0 9px 22px rgba(9,154,167,.22);

        transition:
            transform .2s ease,
            box-shadow .2s ease;
    }


    .login-button:hover {

        transform: translateY(-1px);

        box-shadow:
            0 13px 28px rgba(9,154,167,.28);
    }


    .login-button:active {

        transform: translateY(0);
    }


    .login-button svg {

        width: 17px;
        height: 17px;
    }


    /* Security */

    .security {

        margin-top: 21px;

        display: flex;

        align-items: center;

        justify-content: center;

        gap: 7px;

        color: #8b999b;

        font-size: 10px;
    }


    .security svg {

        width: 14px;
        height: 14px;

        color: var(--primary);
    }


    /* Footer */

    .login-footer {

        margin-top: 42px;

        padding-top: 20px;

        border-top: 1px solid #edf1f2;

        text-align: center;

        color: #9aa6a8;

        font-size: 10px;

        line-height: 1.7;
    }


    /* =========================================================
       RESPONSIVE
    ========================================================= */

    @media (max-width: 1050px) {

        .portal-page {

            grid-template-columns: 53% 47%;
        }


        .visual-content {

            padding-left: 45px;
            padding-right: 45px;
        }


        .visual-title {

            font-size: 50px;
        }


        .feature-grid {

            grid-template-columns: 1fr;
            max-width: 360px;
        }


        .feature-card {

            min-height: auto;

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 12px;
        }


        .feature-icon {

            margin: 0;

            flex: 0 0 32px;
        }
    }


    @media (max-width: 800px) {

        body {

            overflow-y: auto;
        }


        .portal-page {

            min-height: 100vh;

            display: block;
        }


        .visual-section {

            min-height: 300px;
        }


        .visual-content {

            min-height: 300px;

            padding:
                28px
                28px
                32px;
        }


        .visual-main {

            margin-top: 35px;
        }


        .visual-title {

            font-size: 42px;
        }


        .visual-description {

            font-size: 14px;

            margin-top: 15px;
        }


        .feature-grid {

            display: none;
        }


        .visual-footer {

            display: none;
        }


        .login-section {

            min-height: auto;

            padding:
                45px
                28px
                55px;
        }
    }


    @media (max-width: 480px) {

        .visual-section {

            min-height: 260px;
        }


        .visual-content {

            min-height: 260px;

            padding:
                23px
                20px
                27px;
        }


        .brand-logo {

            width: 45px;
            height: 45px;

            border-radius: 12px;
        }


        .brand-text strong {

            font-size: 16px;
        }


        .visual-main {

            margin-top: 27px;
        }


        .visual-title {

            font-size: 35px;

            letter-spacing: -1.5px;
        }


        .visual-description {

            display: none;
        }


        .login-section {

            padding:
                35px
                20px
                45px;
        }


        .login-title {

            font-size: 27px;
        }


        .login-top {

            margin-bottom: 27px;
        }


        input,
        .login-button {

            height: 51px;
        }
    }


</style>
</head>
<body>
<div class="portal-page">
<!-- =========================================================
     LEFT / BRANDING
========================================================== -->

<section class="visual-section">

    <div class="circle-one"></div>
    <div class="circle-two"></div>


    <div class="visual-content">


        <!-- BRAND -->

        <div class="brand">

            <img
                src="/assets/img/hms_logo.png"
                alt="WebSplend HMS"
                class="brand-logo"
            >

            <div class="brand-text">

                <strong>
                    WebSplend HMS
                </strong>

                <span>
                    Hospital Management System
                </span>

            </div>

        </div>


        <!-- MAIN -->

        <div class="visual-main">

            <div class="welcome-pill">

                <span class="status-dot"></span>

                Secure Patient Portal

            </div>


            <h1 class="visual-title">

                Your health.<br>

                <span>Your care. Connected.</span>

            </h1>


            <p class="visual-description">

                A simple and secure way to stay connected
                with your hospital. Access appointments,
                prescriptions, medical information, bills
                and essential healthcare services from one place.

            </p>


            <!-- FEATURES -->

            <div class="feature-grid">


                <!-- Appointment -->

                <div class="feature-card">

                    <div class="feature-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <rect
                                x="3"
                                y="4"
                                width="18"
                                height="17"
                                rx="2"
                            ></rect>

                            <line
                                x1="16"
                                y1="2"
                                x2="16"
                                y2="6"
                            ></line>

                            <line
                                x1="8"
                                y1="2"
                                x2="8"
                                y2="6"
                            ></line>

                            <line
                                x1="3"
                                y1="10"
                                x2="21"
                                y2="10"
                            ></line>

                            <path
                                d="M8 14h2"
                            ></path>

                            <path
                                d="M14 14h2"
                            ></path>

                        </svg>

                    </div>

                    <div>

                        <strong>
                            Appointments
                        </strong>

                        <span>
                            Manage your visits
                        </span>

                    </div>

                </div>


                <!-- Records -->

                <div class="feature-card">

                    <div class="feature-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >

                            <path
                                d="M6 2h9l4 4v16H6z"
                            ></path>

                            <path
                                d="M14 2v5h5"
                            ></path>

                            <line
                                x1="9"
                                y1="12"
                                x2="16"
                                y2="12"
                            ></line>

                            <line
                                x1="9"
                                y1="16"
                                x2="16"
                                y2="16"
                            ></line>

                        </svg>

                    </div>

                    <div>

                        <strong>
                            Medical Records
                        </strong>

                        <span>
                            Your health information
                        </span>

                    </div>

                </div>


                <!-- Secure -->

                <div class="feature-card">

                    <div class="feature-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >

                            <rect
                                x="4"
                                y="10"
                                width="16"
                                height="11"
                                rx="2"
                            ></rect>

                            <path
                                d="M8 10V7a4 4 0 0 1 8 0v3"
                            ></path>

                        </svg>

                    </div>

                    <div>

                        <strong>
                            Secure Access
                        </strong>

                        <span>
                            Protected connection
                        </span>

                    </div>

                </div>


            </div>

        </div>


        <!-- FOOTER -->

        <div class="visual-footer">

            <div class="secure-label">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path
                        d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"
                    ></path>
                </svg>

                Secure healthcare access

            </div>


            <div>
                © <?= date('Y') ?> WebSplend HMS
            </div>

        </div>


    </div>

</section>



<!-- =========================================================
     RIGHT / LOGIN
========================================================== -->

<section class="login-section">


    <div class="login-container">


        <!-- HEADER -->

        <div class="login-top">

            <div class="login-symbol">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >

                    <circle
                        cx="12"
                        cy="8"
                        r="4"
                    ></circle>

                    <path
                        d="M4 21a8 8 0 0 1 16 0"
                    ></path>

                </svg>

            </div>


            <h2 class="login-title">
                Welcome back
            </h2>


            <p class="login-subtitle">

                Sign in to your patient account
                to continue to your healthcare dashboard.

            </p>

        </div>



        <!-- ERROR -->

        <?php if ($error !== ''): ?>

            <div class="error">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >

                    <circle
                        cx="12"
                        cy="12"
                        r="9"
                    ></circle>

                    <line
                        x1="12"
                        y1="8"
                        x2="12"
                        y2="12"
                    ></line>

                    <line
                        x1="12"
                        y1="16"
                        x2="12.01"
                        y2="16"
                    ></line>

                </svg>


                <div>

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            </div>

        <?php endif; ?>



        <!-- FORM -->

        <form
            method="post"
            action="/patient/login_process.php"
            autocomplete="on"
        >


            <!-- Hospital -->

            <div class="form-group">

                <label
                    class="form-label"
                    for="hospital_id"
                >
                    Hospital ID
                </label>


                <div class="input-wrapper">


                    <svg
                        class="input-icon"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >

                        <path d="M3 21h18"></path>

                        <path
                            d="M5 21V7l7-4 7 4v14"
                        ></path>

                        <path
                            d="M9 21v-5h6v5"
                        ></path>

                        <path d="M9 10h1"></path>

                        <path d="M14 10h1"></path>

                        <path d="M9 13h1"></path>

                        <path d="M14 13h1"></path>

                    </svg>


                    <input
                        type="text"
                        id="hospital_id"
                        name="hospital_id"
                        placeholder="Enter your Hospital ID"
                        required
                        autocomplete="organization"
                    >

                </div>

            </div>



            <!-- Mobile -->

            <div class="form-group">

                <label
                    class="form-label"
                    for="username"
                >
                    Mobile Number
                </label>


                <div class="input-wrapper">


                    <svg
                        class="input-icon"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >

                        <rect
                            x="6"
                            y="2"
                            width="12"
                            height="20"
                            rx="2"
                        ></rect>

                        <line
                            x1="10"
                            y1="18"
                            x2="14"
                            y2="18"
                        ></line>

                    </svg>


                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter registered mobile number"
                        required
                        autocomplete="username"
                        inputmode="numeric"
                    >

                </div>

            </div>



            <!-- Password -->

            <div class="form-group">

                <label
                    class="form-label"
                    for="password"
                >
                    Password
                </label>


                <div class="input-wrapper">


                    <svg
                        class="input-icon"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >

                        <rect
                            x="4"
                            y="10"
                            width="16"
                            height="11"
                            rx="2"
                        ></rect>

                        <path
                            d="M8 10V7a4 4 0 0 1 8 0v3"
                        ></path>

                    </svg>


                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >


                    <button
                        type="button"
                        class="password-toggle"
                        id="passwordToggle"
                        aria-label="Show password"
                    >

                        <svg
                            id="eyeIcon"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >

                            <path
                                d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"
                            ></path>

                            <circle
                                cx="12"
                                cy="12"
                                r="2.5"
                            ></circle>

                        </svg>

                    </button>

                </div>

            </div>



            <!-- LOGIN BUTTON -->

            <button
                type="submit"
                class="login-button"
            >

                <span>
                    Sign In to Patient Portal
                </span>


                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >

                    <line
                        x1="5"
                        y1="12"
                        x2="19"
                        y2="12"
                    ></line>

                    <polyline
                        points="12 5 19 12 12 19"
                    ></polyline>

                </svg>

            </button>


        </form>



        <!-- SECURITY -->

        <div class="security">

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >

                <rect
                    x="4"
                    y="10"
                    width="16"
                    height="11"
                    rx="2"
                ></rect>

                <path
                    d="M8 10V7a4 4 0 0 1 8 0v3"
                ></path>

            </svg>

            Secure connection · Your information is protected

        </div>



        <!-- FOOTER -->

        <div class="login-footer">

            WebSplend EHR Patient Portal<br>

            Secure access to your hospital services

        </div>
    </div>
</section>
</div>
<script>
    const passwordInput =
        document.getElementById('password');

    const passwordToggle =
        document.getElementById('passwordToggle');

    const eyeIcon =
        document.getElementById('eyeIcon');


    passwordToggle.addEventListener(
        'click',
        function () {

            const isPassword =
                passwordInput.type === 'password';


            passwordInput.type =
                isPassword
                    ? 'text'
                    : 'password';


            passwordToggle.setAttribute(
                'aria-label',
                isPassword
                    ? 'Hide password'
                    : 'Show password'
            );


            if (isPassword) {

                eyeIcon.innerHTML = `
                    <path d="M3 3l18 18"></path>

                    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>

                    <path d="M9.9 5.1A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a17.4 17.4 0 0 1-3.2 3.9"></path>

                    <path d="M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7c1.8 0 3.3-.5 4.6-1.2"></path>
                `;

            } else {

                eyeIcon.innerHTML = `
                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path>

                    <circle
                        cx="12"
                        cy="12"
                        r="2.5"
                    ></circle>
                `;

            }

        }
    );

</script>

</body>

</html>
