from PIL import Image, ImageDraw, ImageFont, ImageFilter
import sys

OUT = sys.argv[1]
SRC = 'C:/GammaDirectory/Development/WebSite/src/assets'
MANROPE = 'C:/GammaDirectory/Development/Blog/node_modules/@fontsource-variable/manrope/files/manrope-latin-wght-normal.woff2'

def font(size, weight='Bold'):
    f = ImageFont.truetype(MANROPE, size)
    f.set_variation_by_name(weight)
    return f
INK = (15, 15, 15)
SKY = (79, 183, 232)          # brand accent #4fb7e8
WHITE = (255, 255, 255)
SOFT = (178, 186, 194)

W, H = 1544, 500
img = Image.new('RGB', (W, H), INK)

# Soft sky-blue glow on the right, the site's dark-section look.
glow = Image.new('RGB', (W, H), INK)
g = ImageDraw.Draw(glow)
g.ellipse((1020, -260, 1820, 540), fill=(22, 62, 84))
g.ellipse((1180, -120, 1640, 340), fill=(36, 102, 134))
glow = glow.filter(ImageFilter.GaussianBlur(120))
img = Image.blend(img, glow, 1.0)
d = ImageDraw.Draw(img)

# Logo, white wordmark.
logo = Image.open(SRC + '/images/gamma-logo-white.png').convert('RGBA')
lh = 64
logo = logo.resize((int(logo.width * lh / logo.height), lh), Image.LANCZOS)
img.paste(logo, (96, 78), logo)

# Chip.
chip_font = font(24, 'Bold')
chip_text = 'Plugin for WooCommerce'
tw = d.textlength(chip_text, font=chip_font)
d.rounded_rectangle((96, 186, 96 + tw + 44, 186 + 52), radius=26, fill=SKY)
d.text((96 + 22, 186 + 26), chip_text, font=chip_font, fill=INK, anchor='lm')

# Headline and line.
h_font = font(64, 'Bold')
d.text((96, 270), 'Rewards and store credits', font=h_font, fill=WHITE)
d.text((96, 346), 'for your WooCommerce shop', font=h_font, fill=SKY)
s_font = font(28, 'Medium')
d.text((98, 438), 'Customers earn credit on paid orders and spend it at checkout.', font=s_font, fill=SOFT)

# Right side: a simple wallet-credit card motif, no words that need translating.
card = Image.new('RGBA', (360, 220), (0, 0, 0, 0))
c = ImageDraw.Draw(card)
c.rounded_rectangle((0, 0, 359, 219), radius=28, fill=(255, 255, 255, 235))
c.ellipse((30, 30, 92, 92), fill=SKY + (255,))
c.rounded_rectangle((112, 40, 300, 58), radius=9, fill=(15, 15, 15, 220))
c.rounded_rectangle((112, 72, 240, 86), radius=7, fill=(150, 160, 170, 200))
c.text((30, 150), '+ €2.40', font=font(46, 'Bold'), fill=INK + (255,), anchor='lm')
c.rounded_rectangle((250, 170, 330, 192), radius=11, fill=SKY + (255,))
card = card.rotate(-6, resample=Image.BICUBIC, expand=True)
shadow = Image.new('RGBA', card.size, (0, 0, 0, 0))
shadow.paste((0, 0, 0, 120), (0, 0), card.split()[3])
shadow = shadow.filter(ImageFilter.GaussianBlur(18))
img.paste(shadow, (1112, 150), shadow)
img.paste(card, (1100, 136), card)

img.save(OUT + '/banner-1544x500.png', optimize=True)
img.resize((772, 250), Image.LANCZOS).save(OUT + '/banner-772x250.png', optimize=True)
print('ok')
