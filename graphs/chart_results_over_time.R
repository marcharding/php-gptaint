# Call Scripts
# Rscript chart_results_over_time.R results_over_time_f1.csv
# Rscript chart_results_over_time.R results_over_time_far.csv
# Rscript chart_results_over_time.R results_over_time_gscore.csv

# Load necessary libraries
library(ggplot2)
library(readr)
library(ggthemes)
library(tidyr)

# Get the CSV file name from the command line arguments
args <- commandArgs(trailingOnly = TRUE)
if (length(args) == 0) {
  stop("Please provide the CSV file as a command-line argument.")
}

csv_file <- args[1]

# Generate the PDF file name by replacing the .csv extension with .pdf
pdf_file <- sub("\\.csv$", ".pdf", csv_file)

# Load the CSV data
data <- read_delim(file.path("csv", csv_file), col_names = TRUE, delim = ";")

# Subset the data to start at 5 repetitions to smooth the chart
data <- data[data$count >= 5, ]

# Reshape the dataset to long format
data_long <- data %>% pivot_longer(cols = -count, names_to = "metric", values_to = "value")

# Create the line chart
p <- ggplot(data_long, aes(x = count, y = value, color = metric)) +
  geom_line(na.rm = TRUE) +
  labs(
    # title = "F1-Score over time",
    x = "Samples",
    y = "F1-Score",
    color = ""
  ) +
  theme_few()

# Save the plot as a PDF
ggsave(paste("pdf/", pdf_file), plot = p)