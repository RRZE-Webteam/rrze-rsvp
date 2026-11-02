<template>
  <Html lang="___RRZE_EMAIL_LANG___" dir="___RRZE_EMAIL_DIR___">
    <Head>
      <title>___RRZE_EMAIL_SUBJECT___</title>
      <style>
        @import "@maizzle/tailwindcss";

        @theme {
          --color-brand: #04316a;
          --color-brand-light: #e9f2fb;
          --color-canvas: #f3f5f7;
          --color-border: #d7dde5;
          --color-ink: #1f2937;
          --color-muted: #5f6b7a;
        }
      </style>
      <style embed>
        @media only screen and (max-width: 640px) {
          .rrze-email-shell {
            padding: 16px 8px !important;
          }

          .rrze-email-header,
          .rrze-email-heading,
          .rrze-email-content,
          .rrze-email-footer {
            padding-left: 20px !important;
            padding-right: 20px !important;
          }

          .rrze-email-title {
            font-size: 24px !important;
            line-height: 30px !important;
          }

          .rrze-email-details th,
          .rrze-email-details td {
            display: block !important;
            width: auto !important;
          }

          .rrze-email-details th {
            padding-bottom: 0 !important;
            border-bottom: 0 !important;
          }

          .rrze-email-details td {
            padding-top: 4px !important;
          }
        }

        .rrze-email-details {
          width: 100%;
          border-collapse: collapse;
        }

        .rrze-email-details th,
        .rrze-email-details td {
          padding: 10px 12px;
          border-bottom: 1px solid #e5e9ef;
          text-align: left;
          vertical-align: top;
        }

        .rrze-email-details th {
          width: 34%;
          color: #5f6b7a;
          font-size: 13px;
          font-weight: 600;
        }

        .rrze-email-content a {
          color: #04316a;
          font-weight: 600;
          text-decoration: underline;
          text-underline-offset: 2px;
        }
      </style>
      <Outlook>
        <style>
          body,
          table,
          td,
          th,
          p,
          a,
          h1 {
            font-family: Arial, Helvetica, sans-serif !important;
          }
          .rrze-email-button-cell {
            padding: 12px 20px !important;
          }
          .rrze-email-button-link {
            padding: 0 !important;
          }
        </style>
      </Outlook>
    </Head>
    <Tailwind>
      <Body
        lang="___RRZE_EMAIL_LANG___"
        dir="___RRZE_EMAIL_DIR___"
        aria-label="___RRZE_EMAIL_SUBJECT___"
        class="m-0 w-full bg-canvas p-0 text-ink [font-family:Arial,Helvetica,sans-serif]"
      >
        <Preheader>___RRZE_EMAIL_SUBJECT___</Preheader>
        <!-- Keep structural spacing and surfaces on table cells for Word-based Outlook. -->
        <table
          role="presentation"
          width="100%"
          cellpadding="0"
          cellspacing="0"
          border="0"
          bgcolor="#f3f5f7"
          class="w-full bg-canvas"
          style="
            border-collapse: collapse;
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
          "
        >
          <tr>
            <td align="center" class="rrze-email-shell px-4 py-8">
              <Container :width="640">
                <table
                  role="presentation"
                  width="100%"
                  cellpadding="0"
                  cellspacing="0"
                  border="0"
                  class="w-full text-left"
                  style="
                    font-family: Arial, Helvetica, sans-serif;
                    border-collapse: separate;
                    border-spacing: 0;
                    mso-table-lspace: 0pt;
                    mso-table-rspace: 0pt;
                  "
                >
                  <tr>
                    <td
                      bgcolor="#ffffff"
                      class="rrze-email-header rounded-t-lg border border-b-0 border-border bg-white px-8 pb-6 pt-7"
                    >
                      <a
                        href="___RRZE_EMAIL_SITE_URL___"
                        class="inline-block no-underline"
                        target="_blank"
                      >
                        <Raw>___RRZE_EMAIL_LOGO___</Raw>
                      </a>
                    </td>
                  </tr>

                  <tr>
                    <td
                      height="4"
                      bgcolor="___RRZE_EMAIL_STATUS_ACCENT___"
                      style="
                        height: 4px;
                        background-color: ___RRZE_EMAIL_STATUS_ACCENT___;
                        font-size: 0;
                        line-height: 4px;
                        mso-line-height-rule: exactly;
                      "
                    >
                      &nbsp;
                    </td>
                  </tr>
                  <tr>
                    <td
                      bgcolor="#ffffff"
                      class="rrze-email-heading border-x border-border bg-white px-8 pb-3 pt-7"
                    >
                      <table
                        role="presentation"
                        width="100%"
                        cellpadding="0"
                        cellspacing="0"
                        border="0"
                        class="w-full"
                        style="
                          border-collapse: collapse;
                          mso-table-lspace: 0pt;
                          mso-table-rspace: 0pt;
                        "
                      >
                        <tr>
                          <td class="pb-6">
                            <table
                              role="presentation"
                              width="100%"
                              cellpadding="0"
                              cellspacing="0"
                              border="0"
                              class="w-full"
                              style="border-collapse: collapse"
                            >
                              <tr>
                                <td
                                  bgcolor="___RRZE_EMAIL_STATUS_SURFACE___"
                                  class="rrze-email-status border-l-4 px-4 py-2.5 text-[13px] font-bold leading-5"
                                  style="
                                    border-left-color: ___RRZE_EMAIL_STATUS_ACCENT___;
                                    background-color: ___RRZE_EMAIL_STATUS_SURFACE___;
                                    color: ___RRZE_EMAIL_STATUS_TEXT___;
                                  "
                                >
                                  ___RRZE_EMAIL_STATUS_LABEL___
                                </td>
                              </tr>
                            </table>
                          </td>
                        </tr>
                      </table>
                      <Text
                        class="m-0 mb-2 text-xs font-bold uppercase tracking-[0.08em] text-brand"
                      >
                        ___RRZE_EMAIL_SITE_NAME___
                      </Text>
                      <Heading
                        class="rrze-email-title m-0 text-[28px] font-bold leading-9 text-ink sm:text-2xl sm:leading-8"
                      >
                        ___RRZE_EMAIL_SUBJECT___
                      </Heading>
                    </td>
                  </tr>

                  <tr>
                    <td
                      bgcolor="#ffffff"
                      class="rrze-email-content border-x border-b border-border bg-white px-8 pb-8 pt-3 text-[15px] leading-6 text-ink"
                    >
                      <Raw>___RRZE_EMAIL_CONTENT___</Raw>
                    </td>
                  </tr>

                  <tr>
                    <td
                      bgcolor="___RRZE_EMAIL_STATUS_SURFACE___"
                      class="rrze-email-footer rounded-b-lg border border-t-0 border-border px-8 py-5 text-xs leading-5 text-muted"
                      style="background-color: ___RRZE_EMAIL_STATUS_SURFACE___"
                    >
                      <Text class="m-0 mb-1 text-xs leading-5">
                        <a
                          href="___RRZE_EMAIL_SITE_URL___"
                          class="font-semibold text-brand no-underline"
                          target="_blank"
                        >
                          ___RRZE_EMAIL_SITE_NAME___
                        </a>
                      </Text>
                      <Text class="m-0 text-xs leading-5 text-muted">
                        <Raw>___RRZE_EMAIL_FOOTER_LINKS___</Raw>
                      </Text>
                    </td>
                  </tr>
                </table>
              </Container>
            </td>
          </tr>
        </table>
      </Body>
    </Tailwind>
  </Html>
</template>
