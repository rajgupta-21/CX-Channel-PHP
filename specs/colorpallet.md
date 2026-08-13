Fastech Website Color Palette

A color palette based on the provided Fastech website screenshot.

Primary Palette

Color             HEX               RGB               Usage

Primary Blue      #00538F         0, 83, 143      Header, navbar,
footer

Accent Red / Pink #ED3059         237, 48, 89     Active links,
icons, CTA
buttons

Warm Beige        #FBECD7         251, 236, 215   RMA/form section
background

White             #FFFFFF         255, 255, 255   Main background,
text on dark
backgrounds

Black             #000000         0, 0, 0         Top bar,
copyright footer

Dark Text         #222222         34, 34, 34      Headings and
primary text

Light Gray        #CACACA         202, 202, 202   Select/dropdown
fields

Core Brand Colors

PRIMARY BLUE    #00538F
ACCENT RED      #ED3059
WARM BEIGE      #FBECD7
WHITE           #FFFFFF
BLACK           #000000
DARK TEXT       #222222
LIGHT GRAY      #CACACA
MUTED BLUE      #709EC0

Recommended Usage

Primary Blue --- #00538F

Use for: - Main navigation - Large section backgrounds - Footer -
Primary brand elements - Important structural UI areas

Accent Red --- #ED3059

Use for: - Active navigation items - CTA buttons - Icons - Links that
need emphasis - Small visual accents

Avoid using this as a large page background.

Warm Beige --- #FBECD7

Use for: - Forms - Request/RMA sections - Secondary content areas -
Highlighted sections

White --- #FFFFFF

Use for: - Main page backgrounds - Cards - Navigation text - Clean
spacing areas

Dark Text --- #222222

Use for: - Headings - Body text - Form labels - Important readable
content

Suggested Design Ratio

For a redesign that maintains the existing Fastech visual identity:

60--70% White / light backgrounds

20--30% Primary Blue

5--10% Accent Red

Beige primarily for highlighted/form sections

CSS Variables

:root {
  --color-primary: #00538F;
  --color-accent: #ED3059;
  --color-beige: #FBECD7;
  --color-white: #FFFFFF;
  --color-black: #000000;
  --color-text: #222222;
  --color-gray: #CACACA;
  --color-muted-blue: #709EC0;
}

Tailwind Configuration

colors: {
  fastech: {
    blue: "#00538F",
    red: "#ED3059",
    beige: "#FBECD7",
    white: "#FFFFFF",
    black: "#000000",
    text: "#222222",
    gray: "#CACACA",
    mutedBlue: "#709EC0",
  },
}

Quick Reference

Role                   Color

Brand                  #00538F
Accent                 #ED3059
Background             #FFFFFF
Secondary Background   #FBECD7
Primary Text           #222222
Dark Bar               #000000
Form Fields            #CACACA
Secondary Text         #709EC0