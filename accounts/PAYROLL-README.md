# Payroll: rules and calculations

How the JEIWS Accounts payroll works out each employee's pay, tax and net salary, and what it records in the books.

> **Check the rates every fiscal year.** The tax slabs and limits below are the built-in defaults. Nepal's Finance Act can change them every Shrawan. Have your auditor confirm them, then update them under **Payroll → Payroll rates**. Nothing in the code needs to change.

The company does **not** contribute to the Social Security Fund (SSF), so there are no SSF deductions. The 1% Social Security Tax band applies to every employee.

---

## 1. Monthly workflow

1. **Create draft.** Choose a Nepali (B.S.) month. A payslip is created for every active employee who worked any part of that month.
2. **Review and recalculate.** Adjust days present, overtime, bonus, other earnings and advance recovery. TDS and net pay are recalculated.
3. **Post to books.** One salary voucher (`PR/…`) is posted. Payslips are then locked.
4. **Mark paid.** Record the payout from a cash or bank account (`PP/…` voucher). With *Email each employee their own payslip* ticked (the default), every employee who has an email address on their record is sent **their own payslip only**. Nobody receives anyone else's pay. The run page shows each person's delivery status, with *Send*, *Resend* and *Send to everyone not yet emailed* buttons. Emails go out after the payment is saved, so a mail problem never affects the books.
5. **Deposit TDS.** Pay the salary TDS to the IRD by the 25th of the following month and record it as a journal voucher.

A posted month can be **voided by an admin**. Its vouchers are cancelled and the month can be run again.

---

## 2. What each employee record needs

| Field | Used for |
|---|---|
| Basic salary (per month) | Pay, pro-rated by days present |
| Joining date / leaving date | Which months they're paid, and part-month working days |
| Marital status | **Single** or **married** tax slabs |
| Gender | 10% tax rebate for women |
| Life insurance premium (per year) | Tax deduction, capped |
| Health insurance premium (per year) | Tax deduction, capped |
| Project | Which project's cost the salary is charged to |

There is no per-employee allowance. Allowance is **Rs 100 per day present for everyone** (see Step 2).

---

## 3. The calculation, step by step

All figures are in rupees. Results are **rounded to whole rupees**, as on a salary sheet.

### Step 1: Days present and part months

A month's basic salary is based on **26 standard days**, whatever the length of the B.S. month (29–32 days).

```
Standard days   = 26                                   (setting: Payroll → Payroll rates)
Days present    = Full month              → 26
                  Joined or left mid-month → working days employed that month
                                             (every day except Saturday), max 26
                  Can be edited for absences; half days allowed; 0 to 26
Pay factor      = Days present ÷ 26
```

Days beyond 26 cannot be entered. Pay them as **overtime**.

### Step 2: Gross pay

```
Basic pay       = Basic salary × Days present ÷ 26     (rounded to rupee)
Allowance pay   = Rs 100 × Days present                (same rate for every employee)
One-off pay     = Overtime + Bonus + Other earnings    (entered for the month)

Gross pay       = Basic pay + Allowance pay + One-off pay
```

A full month's allowance is Rs 100 × 26 = **Rs 2,600**. Absences reduce it by Rs 100 per day (Rs 50 per half day). The Rs 100 rate is a setting under **Payroll → Payroll rates**.

### Step 3: Annual taxable income (projection)

TDS is withheld monthly, but salary tax is an **annual** tax. The system therefore projects the year from a full month's pay (26 days present):

```
Regular monthly pay   = Basic salary + (Rs 100 × 26)
Annual regular salary = Regular monthly pay × 12

Insurance deduction   = min(Life insurance premium,   Rs 40,000)
                      + min(Health insurance premium, Rs 20,000)

Taxable income        = Annual regular salary − Insurance deduction   (not below 0)
```

### Step 4: Annual tax from the slabs

Each band's rate applies only to the income within that band:

**Single (individual)**

| Taxable income | Rate |
|---|---|
| Up to 5,00,000 | 1% (Social Security Tax) |
| 5,00,001 – 7,00,000 | 10% |
| 7,00,001 – 10,00,000 | 20% |
| 10,00,001 – 20,00,000 | 30% |
| 20,00,001 – 50,00,000 | 36% |
| Above 50,00,000 | 39% |

**Married (couple)**

| Taxable income | Rate |
|---|---|
| Up to 6,00,000 | 1% (Social Security Tax) |
| 6,00,001 – 8,00,000 | 10% |
| 8,00,001 – 11,00,000 | 20% |
| 11,00,001 – 20,00,000 | 30% |
| 20,00,001 – 50,00,000 | 36% |
| Above 50,00,000 | 39% |

```
Annual tax = sum over bands of (income falling in the band × band rate)
```

### Step 5: Rebate for women

```
If gender is female:  Annual tax = Annual tax − 10% of Annual tax
```

### Step 6: This month's TDS

```
Regular TDS   = Annual tax ÷ 12 × Pay factor

One-off TDS   = Tax(annual regular salary + this month's one-off pay)
              − Tax(annual regular salary)

TDS           = Regular TDS + One-off TDS          (rounded to rupee)
```

The one-off part means a bonus or overtime is taxed **at the employee's highest band, in the month it is paid**. This stops a Dashain bonus from raising TDS in every other month.

If needed, TDS can be **overridden by hand** on the draft using the *manual* tick box.

### Step 7: Net pay

```
Net pay = Gross pay − TDS − Advance recovery
```

The system refuses to save a payslip whose deductions are larger than its gross pay.

---

## 4. Worked examples

Every figure below was produced by the system itself.

### A. Single man, Rs 50,000 basic, present all 26 days

| | Rs |
|---|---|
| Basic pay | 50,000 |
| Allowance (100 × 26) | 2,600 |
| **Gross pay** | **52,600** |
| Annual salary (52,600 × 12) | 6,31,200 |
| Tax: first 5,00,000 × 1% | 5,000 |
| Tax: next 1,31,200 × 10% | 13,120 |
| **Annual tax** | **18,120** |
| Monthly TDS (18,120 ÷ 12) | 1,510 |
| **Net pay** (52,600 − 1,510) | **51,090** |

**Same month with Rs 5,000 overtime:** tax on 6,36,200 is 18,620, so the overtime adds **500** of TDS (10% band).
TDS = 1,510 + 500 = **2,010**. Gross 57,600. **Net 55,590**.

**Same employee present only 13 days:** basic 50,000 × 13/26 = 25,000; allowance 100 × 13 = 1,300; gross **26,300**; TDS 1,510 × 13/26 = **755**; net **25,545**.

### B. Married woman, Rs 80,000 basic, life insurance Rs 50,000/year, full month

| | Rs |
|---|---|
| Gross pay (80,000 + 2,600 allowance) | 82,600 |
| Annual salary (82,600 × 12) | 9,91,200 |
| Insurance deduction (50,000 capped at 40,000) | − 40,000 |
| Taxable income | 9,51,200 |
| Tax: 6,00,000 × 1% | 6,000 |
| Tax: 2,00,000 × 10% | 20,000 |
| Tax: 1,51,200 × 20% | 30,240 |
| Tax before rebate | 56,240 |
| Rebate for women (10%) | − 5,624 |
| **Annual tax** | **50,616** |
| Monthly TDS | 4,218 |
| **Net pay** (82,600 − 4,218) | **78,382** |

**Same month with a Rs 1,00,000 Dashain bonus:** with the bonus, taxable income is 10,51,200 and tax after rebate is 68,616, so the bonus adds 68,616 − 50,616 = **18,000**.
TDS = 4,218 + 18,000 = **22,218**. Gross 1,82,600. **Net 1,60,382**.

### C. New joiner: Rs 30,000 basic, joined 17 Ashwin 2083 (3 Oct 2026)

Ashwin 2083 ends on 17 Oct. From 3 to 17 Oct is 15 calendar days, including 3 Saturdays (3, 10 and 17 Oct), so the default is **12 days present**.

| | Rs |
|---|---|
| Basic pay (30,000 × 12/26) | 13,846 |
| Allowance (100 × 12) | 1,200 |
| **Gross pay** | **15,046** |
| Annual tax ((30,000 + 2,600) × 12 = 3,91,200 × 1%) | 3,912 |
| TDS (3,912 ÷ 12 × 12/26) | 150 |
| **Net pay** (15,046 − 150) | **14,896** |

**Same employee repaying a Rs 2,000 advance:** net pay 14,896 − 2,000 = **12,896**.

### D. Single, Rs 4,00,000 basic (36% band)

| | Rs |
|---|---|
| Gross pay (4,00,000 + 2,600) | 4,02,600 |
| Annual salary | 48,31,200 |
| 5,00,000 × 1% | 5,000 |
| 2,00,000 × 10% | 20,000 |
| 3,00,000 × 20% | 60,000 |
| 10,00,000 × 30% | 3,00,000 |
| 28,31,200 × 36% | 10,19,232 |
| **Annual tax** | **14,04,232** |
| Monthly TDS | 1,17,019 |
| **Net pay** | **2,85,581** |

---

## 5. What gets recorded in the books

### When payroll is posted (`PR/2083-84/0001` …)

| Account | Debit | Credit |
|---|---|---|
| 6100 Salaries & Allowances | Total gross pay, one line per project | |
| 2150 TDS Payable | | Total TDS |
| 1170 Staff Advances | | Total advance recovered |
| 2170 Salaries Payable | | Total net pay |

Gross pay = TDS + advances + net pay, so the voucher always balances.

Salary lines are **tagged to the employee's project**, so each project's cost and profit include the salaries charged to it. Employees with no project are charged to head office.

### When marked paid (`PP/2083-84/0001` …)

| Account | Debit | Credit |
|---|---|---|
| 2170 Salaries Payable | Total net pay | |
| Cash or bank account chosen | | Total net pay |

### Later, when TDS is deposited to the IRD (record as a journal voucher)

| Account | Debit | Credit |
|---|---|---|
| 2150 TDS Payable | Amount deposited | |
| Bank account | | Amount deposited |

---

## 6. Checks the system enforces

- Days present must be between 0 and 26 (the standard days); half days are allowed. Extra days go in overtime.
- Amounts must be numbers with at most 2 decimals.
- Deductions cannot exceed gross pay.
- Only one live payroll per B.S. month. A voided month can be re-run.
- Posted payroll cannot be edited; it has to be voided by an admin and redone.
- Accountants can run, post and pay payroll. Only admins can void it or change tax rates. Viewers can only read.

---

## 7. Assumptions and points to confirm with your auditor

These are deliberate simplifications. Please confirm they fit your situation:

1. **Projection method.** Monthly TDS assumes the current salary continues for the whole year. If salary changes mid-year, or someone joins or leaves, the year's TDS total can differ slightly from the final annual tax. There is no automatic year-end true-up. Check the year-end figure and use the **manual TDS** override in Ashadh if needed.
2. **Rebate for women.** The 10% rebate is applied to the whole tax, including the 1% Social Security Tax band. Confirm whether your auditor applies it the same way.
3. **Social Security Tax deposit.** The 1% band is included in the TDS figure and the TDS Payable account. The IRD may expect the 1% portion deposited under a separate revenue heading; split it when depositing if your auditor advises.
4. **Bonus and overtime are fully taxable** in the month paid.
5. **Not handled:**
   - Citizen Investment Trust (CIT) or provident-fund contributions and their deductions
   - Remote-area allowance exemption
   - Leave encashment rules
   - Dearness allowance treated separately from other allowances
   - Employees with income other than salary

   Use the manual TDS override for these cases.
6. **Daily-wage site labour** is not part of this payroll. It stays in the site portal's attendance records.

---

## 8. Changing the rates

**Payroll → Payroll rates** (admin only):

- Standard days per month (26)
- Allowance per day present (Rs 100)
- Insurance premium deduction limits (life Rs 40,000, health Rs 20,000)
- Rebate for women (10%)
- Both slab tables (income limit and rate for each band; leave the last band's limit empty)

Saved rates apply to **draft** payroll when you press *Recalculate*. Already-posted payroll is never changed; each payslip stores the standard days it was calculated with. *Reset to defaults* restores the values in this document.

---

## 9. Where it lives in the code

| What | Where |
|---|---|
| Rates, slabs, tax and payslip formulas | `accounts/includes/payroll.php`: `PAYROLL_DEFAULTS`, `annual_salary_tax()`, `calculate_payslip()` |
| Creating, recalculating, posting, paying and voiding runs | Same file: `create_payroll_run()`, `update_payroll_run()`, `post_payroll_run()`, `pay_payroll_run()`, `void_payroll_run()` |
| Rate settings screen (standard days, allowance per day, slabs) | `accounts/payroll-settings.php` |
| Database tables | `acc_employees`, `acc_payroll_runs`, `acc_payslips` in `accounts/db/schema.sql` |

All money is held internally as whole paisa (integers) to avoid rounding drift.
