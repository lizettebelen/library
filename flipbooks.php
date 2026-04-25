<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flip Book - Samuel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@900&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 20px 14px 40px;
            background: #f4f6fa;
            font-family: "Poppins", sans-serif;
        }

        .flipbookkk {
            margin: 24px auto;
            max-width: 1100px;
        }

        .flipbookkk-title {
            color: #ffa200;
            text-align: center;
            font-size: clamp(1.4rem, 6vw, 2.4rem) !important;
            font-family: "Poppins", sans-serif;
            font-weight: 900;
            line-height: 1.15;
            text-shadow: 1px 3px 0 #373737;
            margin: 0 0 14px;
        }

        .flipbookkk iframe {
            width: 100%;
            height: clamp(320px, 68vh, 760px);
            border: 0;
            border-radius: 10px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
            background: #fff;
        }

        @media (max-width: 600px) {
            body {
                padding: 14px 10px 24px;
            }

            .flipbookkk {
                margin: 18px auto;
            }

            .flipbookkk iframe {
                height: clamp(280px, 62vh, 520px);
            }
        }

        @media (min-width: 768px) {
            .flipbookkk-title {
                font-size: clamp(1.6rem, 3vw, 2.5rem) !important;
            }
        }
    </style>
</head>
<body>

    <div class="flipbookkk">
        <h3 class="flipbookkk-title">Denotation &amp; Connotation</h3>
        <iframe src="https://cdn.flipsnack.com/widget/v2/widget.html?hash=fu56p2gym" seamless="seamless" scrolling="no" frameBorder="0" allowFullScreen></iframe>
    </div>

    <div class="flipbookkk">
        <h3 class="flipbookkk-title">Compositions &amp; Narratives</h3>
        <iframe src="https://cdn.flipsnack.com/widget/v2/widget.html?hash=fxcj4pmko" seamless="seamless" scrolling="no" frameBorder="0" allowFullScreen></iframe>
    </div>

    <div class="flipbookkk">
        <h3 class="flipbookkk-title">Patterns &amp; Types (Fonts)</h3>
        <iframe src="https://cdn.flipsnack.com/widget/v2/widget.html?hash=ft95r3fmz" seamless="seamless" scrolling="no" frameBorder="0" allowFullScreen></iframe>
    </div>

</body>
</html>
